<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Pulse;

use DateTimeImmutable;
use DateTimeInterface;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Messaging\Support\InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseDeadLetterSelection
{
    private const FILTER_CONDITIONS = ['messageName', 'exceptionClass', 'failedFrom', 'failedTo'];
    private const SELECTORS_EXAMPLE = 'Send {"messageIds": ["id-1"]} to act on those messages, or {"filter": {"messageName": "order.place"}} to act on every dead letter matching it.';

    /**
     * @param string[]|null $messageIds
     * @param array{messageName?: string, exceptionClass?: string, failedFrom?: DateTimeImmutable, failedTo?: DateTimeImmutable} $filter
     */
    private function __construct(
        private ?array $messageIds,
        private array $filter,
        public readonly ?string $targetChannel,
    ) {
    }

    public static function forReplay(string $payload): self
    {
        return self::fromPayload($payload, true);
    }

    public static function forDelete(string $payload): self
    {
        return self::fromPayload($payload, false);
    }

    public function selectsByFilter(): bool
    {
        return $this->messageIds === null;
    }

    /**
     * @return string[]
     */
    public function messageIds(DeadLetterStore $store): array
    {
        if ($this->messageIds !== null) {
            return $this->messageIds;
        }

        $messageIds = [];
        foreach (PulseDeadLetterScan::newestFirst($store) as $entry) {
            if ($this->matches($entry)) {
                $messageIds[] = $entry->messageId();
            }
        }

        return $messageIds;
    }

    private function matches(PulseDeadLetterEntry $entry): bool
    {
        return ($this->filter['messageName'] ?? $entry->messageName()) === $entry->messageName()
            && ($this->filter['exceptionClass'] ?? $entry->exceptionClass()) === $entry->exceptionClass()
            && (! isset($this->filter['failedFrom']) || $entry->failedAt() >= $this->filter['failedFrom'])
            && (! isset($this->filter['failedTo']) || $entry->failedAt() <= $this->filter['failedTo']);
    }

    private static function fromPayload(string $payload, bool $acceptsTargetChannel): self
    {
        try {
            $selection = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $selection = null;
        }
        if (! $selection instanceof stdClass) {
            throw InvalidArgumentException::create("A Pulse dead letter action payload must be a JSON object such as {\"messageIds\": [\"id-1\"]} or {\"filter\": {\"messageName\": \"order.place\"}}, but `{$payload}` was sent.");
        }
        $selection = (array) $selection;

        $unknown = array_diff(array_keys($selection), ['messageIds', 'filter', 'targetChannel']);
        if ($unknown !== []) {
            throw InvalidArgumentException::create('A Pulse dead letter action payload has an unknown field `' . reset($unknown) . '`. Use only messageIds, filter' . ($acceptsTargetChannel ? ', targetChannel.' : '.'));
        }

        $targetChannel = null;
        if (array_key_exists('targetChannel', $selection)) {
            if (! $acceptsTargetChannel) {
                throw InvalidArgumentException::create('A Pulse dead letter delete takes no `targetChannel`, as a deleted message is not sent anywhere. Remove `targetChannel` from the payload, or use the replay action to send the messages to a channel.');
            }
            if (! is_string($selection['targetChannel']) || $selection['targetChannel'] === '') {
                throw InvalidArgumentException::create('The `targetChannel` of a Pulse dead letter replay must be the name of an existing channel, as in {"messageIds": ["id-1"], "targetChannel": "orders"}. Remove it to replay each message to the channel it was polled from.');
            }
            $targetChannel = $selection['targetChannel'];
        }

        $hasMessageIds = array_key_exists('messageIds', $selection);
        $hasFilter = array_key_exists('filter', $selection);
        if ($hasMessageIds === $hasFilter) {
            throw InvalidArgumentException::create('A Pulse dead letter action selects messages with exactly one of `messageIds` or `filter`, but the payload had ' . ($hasFilter ? 'both' : 'neither') . '. ' . self::SELECTORS_EXAMPLE);
        }

        return $hasMessageIds
            ? new self(self::messageIdsFrom($selection['messageIds']), [], $targetChannel)
            : new self(null, self::filterFrom($selection['filter']), $targetChannel);
    }

    /**
     * @return string[]
     */
    private static function messageIdsFrom(mixed $messageIds): array
    {
        if (! is_array($messageIds) || $messageIds === [] || ! array_is_list($messageIds) || array_filter($messageIds, fn (mixed $messageId): bool => ! is_string($messageId) || $messageId === '') !== []) {
            throw InvalidArgumentException::create('The `messageIds` of a Pulse dead letter action must be a non-empty list of message id strings, as in {"messageIds": ["id-1", "id-2"]}.');
        }

        return $messageIds;
    }

    /**
     * @return array{messageName?: string, exceptionClass?: string, failedFrom?: DateTimeImmutable, failedTo?: DateTimeImmutable}
     */
    private static function filterFrom(mixed $filter): array
    {
        if (! $filter instanceof stdClass) {
            throw InvalidArgumentException::create('The `filter` of a Pulse dead letter action must be a JSON object, as in {"filter": {"messageName": "order.place"}}.');
        }
        $filter = (array) $filter;
        if ($filter === []) {
            throw InvalidArgumentException::create('The `filter` of a Pulse dead letter action is empty, and an empty filter would match every dead letter. Name at least one of ' . implode(', ', self::FILTER_CONDITIONS) . ', or select the messages by id with `messageIds`.');
        }

        $conditions = [];
        foreach ($filter as $condition => $value) {
            if (! in_array($condition, self::FILTER_CONDITIONS, true)) {
                throw InvalidArgumentException::create("The `filter` of a Pulse dead letter action has an unknown condition `{$condition}`. Use only " . implode(', ', self::FILTER_CONDITIONS) . '.');
            }
            if (! is_string($value) || $value === '') {
                throw InvalidArgumentException::create("The `{$condition}` of a Pulse dead letter filter must be a non-empty string.");
            }
            $conditions[$condition] = in_array($condition, ['failedFrom', 'failedTo'], true) ? self::dateTime($condition, $value) : $value;
        }

        if (isset($conditions['failedFrom'], $conditions['failedTo']) && $conditions['failedFrom'] > $conditions['failedTo']) {
            throw InvalidArgumentException::create("The `failedFrom` of a Pulse dead letter filter ({$filter['failedFrom']}) is later than its `failedTo` ({$filter['failedTo']}), so no dead letter can match. Swap the two, or drop one of them.");
        }

        return $conditions;
    }

    private static function dateTime(string $condition, string $value): DateTimeImmutable
    {
        $dateTime = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value) ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s.uP', $value);
        if ($dateTime === false) {
            throw InvalidArgumentException::create("The `{$condition}` of a Pulse dead letter filter is `{$value}`, which is not an ISO-8601 date time. Send it as in 2026-10-20T12:00:00+00:00.");
        }

        return $dateTime;
    }
}
