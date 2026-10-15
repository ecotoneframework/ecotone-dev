<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Console;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\Tag\TagBackfillReport;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;

use function is_bool;

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
final class TagBackfillConsoleCommand
{
    public function __construct(
        private DbalEventStore $dbalEventStore,
    ) {
    }

    #[ConsoleCommand('ecotone:event-store:backfill-tags', 'Indexes #[EventTag] rows for events recorded before their class declared its current tags')]
    public function backfill(
        #[ConsoleParameterOption] ?string $stream = null,
        #[ConsoleParameterOption] ?string $event = null,
        #[ConsoleParameterOption] int $batchSize = 500,
        #[ConsoleParameterOption] ?int $fromNo = null,
        #[ConsoleParameterOption] bool|string $dryRun = false,
        #[ConsoleParameterOption] bool|string $skipUndeserializable = false,
    ): ConsoleCommandResultSet {
        $report = TagBackfillReport::startingFrom($fromNo);
        $this->dbalEventStore->backfillTagsForStream(
            $stream ?? StreamTableRegistry::DEFAULT_STREAM,
            $event,
            $fromNo,
            $batchSize,
            $this->normalizeBoolean($dryRun),
            $this->normalizeBoolean($skipUndeserializable),
            $report,
        );

        $rows = [
            ['Last no processed', (string) ($report->lastProcessedNo() ?? '-')],
            ['Events scanned', (string) $report->eventsScanned()],
            ['Events tagged', (string) $report->eventsTagged()],
            ['Tag counters bumped', (string) $report->tagsBumped()],
            ['Undeserializable events (skipped)', implode(', ', $report->undeserializableNumbers()) ?: '-'],
        ];

        return ConsoleCommandResultSet::create(['Metric', 'Value'], $rows);
    }

    private function normalizeBoolean(bool|string $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value !== 'false' && $value !== '0' && $value !== '';
    }
}
