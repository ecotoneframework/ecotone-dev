<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Pulse;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;

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
final class PulseDeadLetterEntry
{
    private const TRUNCATION_LIMIT_IN_BYTES = 4096;
    private const BUILT_IN_TYPES = ['array', 'string', 'int', 'float', 'bool', 'null', 'mixed', 'object', 'iterable', 'callable'];
    private const TRUNCATION_MARKER = "\n... [truncated]";

    private function __construct(private Message $message)
    {
    }

    public static function from(Message $message): self
    {
        return new self($message);
    }

    public function messageId(): string
    {
        return (string) $this->message->getHeaders()->getMessageId();
    }

    public function messageName(): string
    {
        $headers = $this->message->getHeaders();
        if ($headers->containsKey(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY)) {
            return (string) $headers->get(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY);
        }

        $typeId = $this->header(MessageHeaders::TYPE_ID) ?? '';

        return preg_match('/^[A-Za-z_\\\\][\w\\\\.:-]*$/', $typeId) === 1 && ! in_array($typeId, self::BUILT_IN_TYPES, true) ? $typeId : '';
    }

    public function exceptionClass(): string
    {
        return $this->header(ErrorContext::EXCEPTION_CLASS) ?? '';
    }

    public function failedAt(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . (int) $this->message->getHeaders()->get(MessageHeaders::TIMESTAMP)))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array{messageId: string, messageName: string, failedAt: string, exceptionClass: string, exceptionMessage: string, stackTrace: string, payload: string, contentType: string|null, channel: string|null}
     */
    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId(),
            'messageName' => $this->messageName(),
            'failedAt' => $this->failedAt()->format(DateTimeInterface::ATOM),
            'exceptionClass' => $this->exceptionClass(),
            'exceptionMessage' => $this->header(ErrorContext::EXCEPTION_MESSAGE) ?? '',
            'stackTrace' => self::truncated($this->header(ErrorContext::EXCEPTION_STACKTRACE) ?? ''),
            'payload' => self::truncated($this->payloadAsText()),
            'contentType' => $this->header(MessageHeaders::CONTENT_TYPE),
            'channel' => $this->header(MessageHeaders::POLLED_CHANNEL_NAME) ?? $this->header(MessageHeaders::INBOUND_REQUEST_CHANNEL),
        ];
    }

    private function header(string $name): ?string
    {
        return $this->message->getHeaders()->containsKey($name) ? (string) $this->message->getHeaders()->get($name) : null;
    }

    private function payloadAsText(): string
    {
        $payload = $this->message->getPayload();

        if (is_string($payload)) {
            return $payload;
        }

        $encoded = json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? get_debug_type($payload) : $encoded;
    }

    private static function truncated(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        if (strlen($text) <= self::TRUNCATION_LIMIT_IN_BYTES) {
            return $text;
        }

        return mb_strcut($text, 0, self::TRUNCATION_LIMIT_IN_BYTES - strlen(self::TRUNCATION_MARKER), 'UTF-8') . self::TRUNCATION_MARKER;
    }
}
