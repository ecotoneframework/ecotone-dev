<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use function count;

use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\PdoStreamTableNameProvider;
use Ecotone\EventSourcing\Projecting\StreamEvent;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Projecting\StreamFilterRegistry;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;

use function in_array;
use function strlen;

class EventStoreGlobalStreamSource implements StreamSource
{
    /**
     * @param string[] $handledProjectionNames
     */
    public function __construct(
        private DbalConnectionFactory|ManagerRegistryConnectionFactory|MultiTenantConnectionFactory $connectionFactory,
        private EcotoneClockInterface $clock,
        private PdoStreamTableNameProvider $tableNameProvider,
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

        if (count($streamFilters) === 1) {
            return $this->loadFromSingleStream($streamFilters[0], $lastPosition, $count);
        }

        return $this->loadFromMultipleStreams($streamFilters, $lastPosition, $count);
    }

    private function loadFromSingleStream(\Ecotone\Projecting\StreamFilter $streamFilter, ?string $lastPosition, int $count): StreamPage
    {
        $connection = $this->getConnection();
        $proophStreamTable = $this->tableNameProvider->generateTableNameForStream($streamFilter->streamName);

        if (empty($lastPosition) && ! SchemaManagerCompatibility::tableExists($connection, $proophStreamTable)) {
            return new StreamPage([], '');
        }

        $tracking = GapAwarePosition::fromString($lastPosition);

        [$gapQueryPart, $gapQueryPartParams, $gapQueryPartParamTypes] = match (($gaps = $tracking->getGaps()) > 0) {
            true => ['OR no IN (:gaps)', ['gaps' => $gaps], ['gaps' => ArrayParameterType::INTEGER]],
            false => ['', [], []],
        };

        $query = $connection->executeQuery(<<<SQL
            SELECT no, event_name, payload, metadata, created_at
                FROM {$proophStreamTable}
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
        foreach ($query->iterateAssociative() as $event) {
            $events[] = $event = new StreamEvent(
                $event['event_name'],
                json_decode($event['payload'], true),
                json_decode($event['metadata'], true),
                (int) $event['no'],
                $this->getTimestamp($event['created_at'])
            );
            $insertGaps = $event->timestamp > $cutoffTimestamp;
            $tracking->advanceTo($event->no, $insertGaps);
        }

        $tracking->cleanByMaxOffset($this->maxGapOffset);

        $this->cleanGapsByTimeout($tracking, $connection, $proophStreamTable);

        return new StreamPage($events, (string) $tracking);
    }

    /**
     * @param \Ecotone\Projecting\StreamFilter[] $streamFilters
     */
    private function loadFromMultipleStreams(array $streamFilters, ?string $lastPosition, int $count): StreamPage
    {
        $positions = $this->decodeMultiStreamPositions($lastPosition);

        $orderIndex = [];
        $i = 0;
        $newPositions = [];
        $all = [];

        foreach ($streamFilters as $streamFilter) {
            $streamName = $streamFilter->streamName;
            $orderIndex[$streamName] = $i++;

            $streamPosition = $positions[$streamName] ?? null;
            $limit = (int) ceil($count / max(1, count($streamFilters))) + 5;

            $streamPage = $this->loadFromSingleStream($streamFilter, $streamPosition, $limit);
            $newPositions[$streamName] = $streamPage->lastPosition;

            foreach ($streamPage->events as $event) {
                $all[] = [$streamName, $event];
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
            if ($pair === '') {
                continue;
            }
            [$stream, $pos] = explode('=', $pair, 2);
            $result[$stream] = $pos;
        }
        return $result;
    }

    private function cleanGapsByTimeout(GapAwarePosition $tracking, Connection $connection, string $proophStreamTable): void
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
                FROM {$proophStreamTable}
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
