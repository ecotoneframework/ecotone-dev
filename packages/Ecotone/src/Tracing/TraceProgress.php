<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

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
     * @param array<string, int> $sent
     * @param array<string, int> $processed
     * @param array<string, int> $failed
     */
    public function __construct(
        public readonly string $traceId,
        public readonly bool $recorded,
        public readonly bool $rootEnded,
        public readonly array $sent,
        public readonly array $processed,
        public readonly array $failed,
    ) {
    }

    public static function unknown(string $traceId): self
    {
        return new self($traceId, false, false, [], [], []);
    }

    public function isCompleted(): bool
    {
        return $this->recorded && $this->rootEnded && $this->pendingDestinations() === [];
    }

    public function isCompletedFor(string $destination): bool
    {
        if (! $this->recorded) {
            return false;
        }
        $processed = $this->processed[$destination] ?? 0;

        return $processed > 0 && $processed >= ($this->sent[$destination] ?? 0);
    }

    /**
     * @return string[]
     */
    public function pendingDestinations(): array
    {
        $pending = [];
        foreach ($this->sent as $destination => $sent) {
            if (($this->processed[$destination] ?? 0) < $sent) {
                $pending[] = $destination;
            }
        }

        return $pending;
    }

    /**
     * @return string[]
     */
    public function destinations(): array
    {
        return array_keys($this->sent + $this->processed + $this->failed);
    }
}
