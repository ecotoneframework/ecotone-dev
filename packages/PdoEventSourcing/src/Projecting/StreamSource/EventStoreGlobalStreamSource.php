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
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Dbal\AlreadyConnectedDbalConnectionFactory;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Connection\ManagerRegistryConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\Database\MissingEventStreamTable;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\Projecting\StreamEvent;
use Ecotone\EventSourcing\StreamTableRegistry;
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
        private MissingEventStreamTable $missingEventStreamTable,
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
     * @return StreamEvent[]
     */
    private function loadTableEvents(Connection $connection, string $streamTable, GapAwarePosition $tracking, int $count): array
    {
        $quotedTable = EventStreamSchemaFactory::for($connection)->quoteIdentifier($streamTable);
        [$gapQueryPart, $gapQueryPartParams, $gapQueryPartParamTypes] = match (($gaps = $tracking->getGaps()) > 0) {
            true => ['OR no IN (:gaps)', ['gaps' => $gaps], ['gaps' => ArrayParameterType::INTEGER]],
            false => ['', [], []],
        };

        try {
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
            foreach ($query->iterateAssociative() as $row) {
                $events[] = new StreamEvent(
                    $row['event_name'],
                    json_decode($row['payload'], true),
                    json_decode($row['metadata'], true) ?? [],
                    (int) $row['no'],
                    $this->getTimestamp($row['created_at'])
                );
            }
        } catch (TableNotFoundException) {
            throw $this->missingEventStreamTable->exceptionFor($streamTable);
        }

        return $events;
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
        $connection = $this->getConnection();
        $cutoffTimestamp = $this->gapTimeout ? $this->clock->now()->sub($this->gapTimeout)->getTimestamp() : 0;

        $filtersByTable = [];
        foreach ($streamFilters as $streamFilter) {
            $filtersByTable[$this->streamTableRegistry->tableFor($streamFilter->streamName)][] = $streamFilter;
        }
        $limit = count($filtersByTable) === 1 ? $count : (int) ceil($count / count($filtersByTable)) + 5;

        $tracking = [];
        $tableEvents = [];
        $offsets = [];
        foreach ($filtersByTable as $streamTable => $tableFilters) {
            $tracking[$streamTable] = GapAwarePosition::fromString($positions[$streamTable] ?? null);
            $tableEvents[$streamTable] = $this->loadTableEvents($connection, $streamTable, $tracking[$streamTable], $limit);
            $offsets[$streamTable] = 0;
        }

        $events = [];
        while (count($events) < $count) {
            $nextEvent = null;
            $nextTable = null;
            foreach ($filtersByTable as $streamTable => $tableFilters) {
                while (true) {
                    $candidate = $tableEvents[$streamTable][$offsets[$streamTable]] ?? null;
                    if ($candidate === null && count($tableEvents[$streamTable]) === $limit) {
                        $tableEvents[$streamTable] = $this->loadTableEvents($connection, $streamTable, $tracking[$streamTable], $limit);
                        $offsets[$streamTable] = 0;
                        continue;
                    }
                    if ($candidate === null || $this->matchesAnyFilter($tableFilters, $candidate->getEventName(), $candidate->getMetadata())) {
                        break;
                    }

                    $tracking[$streamTable]->advanceTo($candidate->no, $candidate->timestamp > $cutoffTimestamp);
                    $offsets[$streamTable]++;
                }

                if ($candidate !== null && ($nextEvent === null || $candidate->timestamp < $nextEvent->timestamp)) {
                    $nextEvent = $candidate;
                    $nextTable = $streamTable;
                }
            }

            if ($nextEvent === null) {
                break;
            }

            $events[] = $nextEvent;
            $tracking[$nextTable]->advanceTo($nextEvent->no, $nextEvent->timestamp > $cutoffTimestamp);
            $offsets[$nextTable]++;
        }

        $newPositions = [];
        foreach ($tracking as $streamTable => $tableTracking) {
            $unprojectedFilledGaps = [];
            foreach (array_slice($tableEvents[$streamTable], $offsets[$streamTable]) as $unprojectedEvent) {
                if ($unprojectedEvent->no <= $tableTracking->getPosition()) {
                    $unprojectedFilledGaps[] = $unprojectedEvent->no;
                }
            }

            $tableTracking->cleanByMaxOffset($this->maxGapOffset);
            $this->cleanGapsByTimeout($tableTracking, $connection, EventStreamSchemaFactory::for($connection)->quoteIdentifier($streamTable));
            $newPositions[$streamTable] = (string) new GapAwarePosition(
                $tableTracking->getPosition(),
                [...$tableTracking->getGaps(), ...$unprojectedFilledGaps],
            );
        }

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
        $dateString = str_replace('T', ' ', $dateString);
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
