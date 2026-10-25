<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing\Pulse;

use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshotSection;

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
final class PulseTracingStorage implements PulseSnapshotSection
{
    public function __construct(private bool $inMemory, private int $retentionInSeconds)
    {
    }

    /**
     * @return array{storage: string, retention: int, statsWindow: int}
     */
    public function snapshot(): array
    {
        return [
            'storage' => $this->inMemory ? 'in_memory' : 'dbal',
            'retention' => $this->retentionInSeconds,
            'statsWindow' => PulseTracing::STATISTICS_WINDOW,
        ];
    }
}
