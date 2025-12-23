<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;

use function strlen;

/**
 * Multi-stream source for Prooph, where each stream has its own table and sequence.
 * We maintain a GapAwarePosition per stream and interleave by created_at.
 */
class EventStoreMultiStreamSource implements StreamSource
{
    /**
     * @param array<string,string> $streamToTable map of logical stream name => prooph table name
     */
    public function __construct(
        private DbalConnectionFactory|ManagerRegistryConnectionFactory|MultiTenantConnectionFactory $connectionFactory,
        private EcotoneClockInterface $clock,
        private array $streamToTable,
        private int $maxGapOffset = 5_000,
        private ?Duration $gapTimeout = null,
    ) {
        Assert::isTrue(!empty($streamToTable), 'At least one stream must be provided');
    }

    private function getConnection(): Connection
    {
        if ($this->connectionFactory instanceof MultiTenantConnectionFactory) {
            return $this->connectionFactory->getConnection();
        }

        return $this->connectionFactory->createContext()->getDbalConnection();
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::null($partitionKey, 'Partition key is not supported for EventStoreMultiStreamSource');

        $connection = $this->getConnection();

        if (empty($lastPosition)) {
            // if none of the tables exist yet, return empty
            $anyExists = false;
            foreach ($this->streamToTable as $table) {
                if (SchemaManagerCompatibility::tableExists($connection, $table)) {
                    $anyExists = true;
                    break;
                }
            }
            if (! $anyExists) {
                return new StreamPage([], '');
            }
        }

        $positions = $this->decodePositions($lastPosition);

        $now = $this->clock->now();
        $cutoffTimestamp = $this->gapTimeout ? $now->sub($this->gapTimeout)->getTimestamp() : 0;

        $perStreamRows = [];
        $orderIndex = [];
        $i = 0;
        foreach ($this->streamToTable as $stream => $table) {
            $orderIndex[$stream] = $i++;
            $tracking = GapAwarePosition::fromString($positions[$stream] ?? null);

            [$gapQueryPart, $gapQueryParams, $gapQueryTypes] = match (($gaps = $tracking->getGaps()) > 0) {
                true => ['OR no IN (:gaps)', ['gaps' => $gaps], ['gaps' => \Doctrine\DBAL\ArrayParameterType::INTEGER]],
                false => ['', [], []],
            };

            $limit = max((int)ceil($count / max(1, count($this->streamToTable))) + 5, 10);

            $query = $connection->executeQuery(<<<SQL
                SELECT no, event_name, payload, metadata, created_at
                FROM {$table}
                WHERE no > :position {$gapQueryPart}
                ORDER BY no
                LIMIT {$limit}
            SQL, [
                'position' => $tracking->getPosition(),
                ...$gapQueryParams,
            ], $gapQueryTypes);

            $rows = [];
            foreach ($query->iterateAssociative() as $event) {
                $rows[] = [
                    'stream' => $stream,
                    'no' => (int)$event['no'],
                    'event_name' => $event['event_name'],
                    'payload' => $event['payload'],
                    'metadata' => $event['metadata'],
                    'created_at' => $event['created_at'],
                    'ts' => $this->getTimestamp($event['created_at']),
                ];
            }

            $perStreamRows[$stream] = [
                'rows' => $rows,
                'tracking' => $tracking,
            ];
        }

        // Merge all rows by created_at ASC, tie-break by stream order, then no
        $all = [];
        foreach ($perStreamRows as $stream => $pack) {
            foreach ($pack['rows'] as $row) {
                $all[] = $row;
            }
        }

        usort($all, function (array $a, array $b) use ($orderIndex): int {
            if ($a['ts'] === $b['ts']) {
                $ai = $orderIndex[$a['stream']] <=> $orderIndex[$b['stream']];
                if ($ai !== 0) {
                    return $ai;
                }
                return $a['no'] <=> $b['no'];
            }
            return $a['ts'] <=> $b['ts'];
        });

        // Take first $count and advance per-stream positions accordingly
        $selected = array_slice($all, 0, $count);

        $events = [];
        foreach ($selected as $row) {
            $events[] = Event::createWithType(
                $row['event_name'],
                json_decode($row['payload'], true),
                json_decode($row['metadata'], true),
            );

            $tracking = $perStreamRows[$row['stream']]['tracking'];
            $insertGaps = $row['ts'] > $cutoffTimestamp;
            $tracking->advanceTo((int)$row['no'], $insertGaps);
        }

        // Cleanup per-stream trackers and encode position
        foreach ($perStreamRows as $stream => $pack) {
            /** @var GapAwarePosition $tracking */
            $tracking = $pack['tracking'];
            $tracking->cleanByMaxOffset($this->maxGapOffset);
            $this->cleanGapsByTimeout($tracking, $connection, $this->streamToTable[$stream]);
            $positions[$stream] = (string)$tracking;
        }

        return new StreamPage($events, $this->encodePositions($positions));
    }

    private function cleanGapsByTimeout(GapAwarePosition $tracking, Connection $connection, string $table): void
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
            FROM {$table}
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

    /**
     * Encodes map as: stream=position:g1,g2;stream2=position:...;
     */
    private function encodePositions(array $positions): string
    {
        ksort($positions);
        $parts = [];
        foreach ($positions as $stream => $pos) {
            $parts[] = $stream . '=' . (string)$pos . ';';
        }
        return implode('', $parts);
    }

    /**
     * Decodes the map encoded by encodePositions.
     * Returns array<string,string>
     */
    private function decodePositions(?string $position): array
    {
        $result = [];
        if ($position === null || $position === '') {
            return $result;
        }
        $pairs = explode(';', rtrim($position, ';'));
        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            [$stream, $pos] = explode('=', $pair, 2);
            $result[$stream] = $pos;
        }
        return $result;
    }
}
