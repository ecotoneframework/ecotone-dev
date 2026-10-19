<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use function array_keys;

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
 */
final class TraceProgress
{
    /**
     * @param array<string, array<string, true>> $sent
     * @param array<string, array<string, true>> $processed
     * @param array<string, array<string, true>> $sentDeliveries
     * @param array<string, array<string, true>> $processedDeliveries
     * @param array<string, string> $causedBy
     */
    public function __construct(
        public readonly string $traceId,
        public readonly bool $recorded,
        public readonly bool $rootEnded,
        public readonly array $sent,
        public readonly array $processed,
        public readonly array $sentDeliveries,
        public readonly array $processedDeliveries,
        public readonly array $causedBy,
    ) {
    }

    public static function unknown(string $traceId): self
    {
        return new self($traceId, false, false, [], [], [], [], []);
    }

    public function isCompleted(): bool
    {
        return $this->recorded && $this->rootEnded && $this->pendingDestinations() === [];
    }

    public function isCompletedFor(string ...$destinations): bool
    {
        if (! $this->recorded) {
            return false;
        }
        $processed = [];
        foreach ($destinations as $destination) {
            if (array_diff_key($this->sent[$destination] ?? [], $this->processed[$destination] ?? []) !== []) {
                return false;
            }
            $processed += $this->processed[$destination] ?? [];
        }
        if ($processed === []) {
            return false;
        }

        foreach ($this->pendingMessageIds() as $pendingMessageId) {
            if (! $this->isCausedByOneOf($pendingMessageId, $processed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function pendingMessageIds(): array
    {
        $pending = [];
        foreach ($this->sent as $destination => $sent) {
            $pending = [...$pending, ...array_keys(array_diff_key($sent, $this->processed[$destination] ?? []))];
        }
        foreach ($this->sentDeliveries as $channel => $sent) {
            $pending = [...$pending, ...array_keys(array_diff_key($sent, $this->processedDeliveries[$channel] ?? []))];
        }

        return array_map('strval', $pending);
    }

    /**
     * @param array<string, true> $causes
     */
    private function isCausedByOneOf(string $messageId, array $causes): bool
    {
        $visited = [$messageId => true];
        $current = $this->causedBy[$messageId] ?? null;
        while ($current !== null && ! isset($visited[$current])) {
            if (isset($causes[$current])) {
                return true;
            }
            $visited[$current] = true;
            $current = $this->causedBy[$current] ?? null;
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function pendingDestinations(): array
    {
        $pending = [];
        foreach ($this->sent as $destination => $sent) {
            if (array_diff_key($sent, $this->processed[$destination] ?? []) !== []) {
                $pending[] = $destination;
            }
        }
        foreach ($this->sentDeliveries as $channel => $messages) {
            if (array_diff_key($messages, $this->processedDeliveries[$channel] ?? []) !== []) {
                $pending[] = $channel;
            }
        }

        return array_values(array_unique($pending));
    }

    public function reached(string ...$destinations): bool
    {
        foreach ($destinations as $destination) {
            if (isset($this->sent[$destination]) || isset($this->processed[$destination])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function destinations(): array
    {
        return array_keys($this->sent + $this->processed);
    }
}
