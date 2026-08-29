<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use function count;

use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ecotone\Api\EcotoneClockInterface;
use Ecotone\Dbal\AlreadyConnectedDbalConnectionFactory;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Connection\ManagerRegistryConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\Projecting\StreamEvent;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Config\Routing\BusRoutingMap;
use Ecotone\Projecting\StreamFilterRegistry;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;

use function in_array;
use function strlen;

class EventStoreGlobalStreamSource implements StreamSource
{
    /**
     * @param string[] $handledProjectionNames
     */
    public function __construct(
        private DbalConnectionFactory|ManagerRegistryConnectionFactory|MultiTenantConnectionFactory|AlreadyConnectedDbalConnectionFactory $connectionFactory,
        private EcotoneClockInterface $clock,
        private StreamTableRegistry $streamTableRegistry,
        private StreamFilterRegistry $streamFilterRegistry,
        private array $handledProjectionNames,
        private int $maxGapOffset = 5_000,
        private ?Duration $gapTimeout = null,
    ) {
    }

    public function canHandle(string $projectionName): bool
    {
        return in_array($projectionName, $this->handledProjectionNames, true);
    }

    private function getConnection(): Connection
    {
        if ($this->connectionFactory instanceof MultiTenantConnectionFactory) {
            return $this->connectionFactory->getConnection();
        }

        return $this->connectionFactory->createContext()->getDbalConnection();
    }

    public function load(string $projectionName, ?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::null($partitionKey, 'Partition key is not supported for EventStoreGlobalStreamSource');

        $streamFilters = $this->streamFilterRegistry->provide($projectionName);
        Assert::isTrue(count($streamFilters) > 0, "No stream filter found for projection: {$projectionName}");

        return $this->loadFromMultipleStreams($streamFilters, $lastPosition, $count);
    }

    /**
     * @param \Ecotone\Projecting\StreamFilter[] $streamFilters
     */
    private function loadFromSingleTable(string $streamTable, array $streamFilters, ?string $lastPosition, int $count): StreamPage
    {
        $connection = $this->getConnection();
        $schema = EventStreamSchemaFactory::for($connection);

        if (! $schema->tableExists($connection, $streamTable)) {
            return new StreamPage([], '');
        }

        $quotedTable = $schema->quoteIdentifier($streamTable);

        $tracking = GapAwarePosition::fromString($lastPosition);

        [$gapQueryPart, $gapQueryPartParams, $gapQueryPartParamTypes] = match (($gaps = $tracking->getGaps()) > 0) {
            true => ['OR no IN (:gaps)', ['gaps' => $gaps], ['gaps' => ArrayParameterType::INTEGER]],
            false => ['', [], []],
        };

        $query = $connection->executeQuery(<<<SQL
            SELECT no, event_name, payload, metadata, created_at
                FROM {$quotedTable}
                WHERE no > :position {$gapQueryPart}
            ORDER BY no
            LIMIT {$count}
            SQL, [
            'position' => $tracking->getPosition(),
            ...$gapQueryPartParams,
        ], $gapQueryPartParamTypes);

        $events = [];
        $now = $this->clock->now();
        $cutoffTimestamp = $this->gapTimeout ? $now->sub($this->gapTimeout)->getTimestamp() : 0;
        foreach ($query->iterateAssociative() as $row) {
            $metadata = json_decode($row['metadata'], true) ?? [];
            $event = new StreamEvent(
                $row['event_name'],
                json_decode($row['payload'], true),
                $metadata,
                (int) $row['no'],
                $this->getTimestamp($row['created_at'])
            );
            if ($this->matchesAnyFilter($streamFilters, $row['event_name'], $metadata)) {
                $events[] = $event;
            }
            $insertGaps = $event->timestamp > $cutoffTimestamp;
            $tracking->advanceTo($event->no, $insertGaps);
        }

        $tracking->cleanByMaxOffset($this->maxGapOffset);

        $this->cleanGapsByTimeout($tracking, $connection, $quotedTable);

        return new StreamPage($events, (string) $tracking);
    }

    /**
     * @param \Ecotone\Projecting\StreamFilter[] $streamFilters
     * @param array<string, mixed> $metadata
     */
    private function matchesAnyFilter(array $streamFilters, string $eventName, array $metadata): bool
    {
        foreach ($streamFilters as $streamFilter) {
            if ($streamFilter->aggregateType !== null && ($metadata[MessageHeaders::EVENT_AGGREGATE_TYPE] ?? null) !== $streamFilter->aggregateType) {
                continue;
            }
            if ($streamFilter->eventNames !== [] && ! $this->matchesEventName($streamFilter->eventNames, $eventName)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string> $eventNames
     */
    private function matchesEventName(array $eventNames, string $eventName): bool
    {
        foreach ($eventNames as $pattern) {
            if (BusRoutingMap::globMatch($pattern, $eventName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Ecotone\Projecting\StreamFilter[] $streamFilters
     */
    private function loadFromMultipleStreams(array $streamFilters, ?string $lastPosition, int $count): StreamPage
    {
        $positions = $this->decodeMultiStreamPositions($lastPosition);

        $filtersByTable = [];
        foreach ($streamFilters as $streamFilter) {
            $filtersByTable[$this->streamTableRegistry->tableFor($streamFilter->streamName)][] = $streamFilter;
        }

        $orderIndex = [];
        $i = 0;
        $newPositions = [];
        $all = [];

        foreach ($filtersByTable as $streamTable => $tableFilters) {
            $orderIndex[$streamTable] = $i++;

            $tableCount = count($filtersByTable);
            $limit = $tableCount === 1 ? $count : (int) ceil($count / $tableCount) + 5;

            $streamPage = $this->loadFromSingleTable($streamTable, $tableFilters, $positions[$streamTable] ?? null, $limit);
            $newPositions[$streamTable] = $streamPage->lastPosition;

            foreach ($streamPage->events as $event) {
                $all[] = [$streamTable, $event];
            }
        }

        usort($all, function (array $aTuple, array $bTuple) use ($orderIndex): int {
            [$aStream, $a] = $aTuple;
            [$bStream, $b] = $bTuple;
            if ($aStream === $bStream) {
                return $a->no <=> $b->no;
            }
            if ($a->timestamp === $b->timestamp) {
                return $orderIndex[$aStream] <=> $orderIndex[$bStream];
            }
            return $a->timestamp <=> $b->timestamp;
        });

        $events = array_map(fn (array $tuple) => $tuple[1], $all);

        return new StreamPage($events, $this->encodeMultiStreamPositions($newPositions));
    }

    private function encodeMultiStreamPositions(array $positions): string
    {
        $encoded = '';
        foreach ($positions as $stream => $pos) {
            $encoded .= "{$stream}={$pos};";
        }
        return $encoded;
    }

    /**
     * @return array<string, string>
     */
    private function decodeMultiStreamPositions(?string $position): array
    {
        $result = [];
        if ($position === null || $position === '') {
            return $result;
        }
        $pairs = explode(';', $position);
        foreach ($pairs as $pair) {
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }
            [$stream, $pos] = explode('=', $pair, 2);
            $result[$stream] = $pos;
        }
        return $result;
    }

    private function cleanGapsByTimeout(GapAwarePosition $tracking, Connection $connection, string $quotedTable): void
    {
        if ($this->gapTimeout === null) {
            return;
        }
        $gaps = $tracking->getGaps();
        if (empty($gaps)) {
            return;
        }

        $minGap = $gaps[0];
        $maxGap = $gaps[count($gaps) - 1];

        $interleavedEvents = $connection->executeQuery(<<<SQL
            SELECT no, created_at
                FROM {$quotedTable}
                WHERE no >= :minPosition and no <= :maxPosition
            ORDER BY no
            LIMIT 100
            SQL, [
            'minPosition' => $minGap,
            'maxPosition' => $maxGap + 1,
        ])->iterateAssociative();

        $timestampThreshold = $this->clock->now()->sub($this->gapTimeout)->unixTime()->inSeconds();

        $cutoffPosition = $minGap;
        foreach ($interleavedEvents as $event) {
            $interleavedEventPosition = $event['no'];
            $timestamp = $this->getTimestamp($event['created_at']);

            if ($timestamp > $timestampThreshold) {
                break;
            }
            if (in_array($interleavedEventPosition, $gaps, true)) {
                break;
            }
            if ($timestamp < $timestampThreshold && $interleavedEventPosition > $cutoffPosition) {
                $cutoffPosition = $interleavedEventPosition + 1;
            }
        }

        $tracking->cutoffGapsBelow($cutoffPosition);
    }

    private function getTimestamp(string $dateString): int
    {
        if (strlen($dateString) === 19) {
            $dateString = $dateString . '.000';
        }
        return DatePoint::createFromFormat(
            'Y-m-d H:i:s.u',
            $dateString,
            new DateTimeZone('UTC')
        )->getTimestamp();
    }
}
