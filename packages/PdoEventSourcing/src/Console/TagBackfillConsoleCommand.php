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
            ['Last no processed', (string) $report->lastNo()],
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
