<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
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

class EventStoreGlobalStreamSource implements StreamSource
{
    private Connection $connection;

    public function __construct(
        DbalConnectionFactory|ManagerRegistryConnectionFactory $connectionFactory,
        private EcotoneClockInterface $clock,
        private string $proophStreamTable,
        private int $maxGapOffset = 5_000,
        private ?Duration $gapTimeout = null,
    ) {
        $this->connection = $connectionFactory->createContext()->getDbalConnection();
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::null($partitionKey, 'Partition key is not supported for EventStoreGlobalStreamSource');

        if (empty($lastPosition) && ! SchemaManagerCompatibility::tableExists($this->connection, $this->proophStreamTable)) {
            return new StreamPage([], '');
        }

        $tracking = GapAwarePosition::fromString($lastPosition);

        [$gapQueryPart, $gapQueryPartParams, $gapQueryPartParamTypes] = match (($gaps = $tracking->getGaps()) > 0) {
            true => ['OR no IN (:gaps)', ['gaps' => $gaps], ['gaps' => ArrayParameterType::INTEGER]],
            false => ['', [], []],
        };

        $query = $this->connection->executeQuery(<<<SQL
            SELECT no, event_name, payload, metadata, created_at
                FROM {$this->proophStreamTable}
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
            $events[] = Event::createWithType(
                $event['event_name'],
                json_decode($event['payload'], true),
                json_decode($event['metadata'], true),
            );
            $timestamp = $this->getTimestamp($event['created_at']);
            $insertGaps = $timestamp > $cutoffTimestamp;
            $tracking->advanceTo((int) $event['no'], $insertGaps);
        }

        $tracking->cleanByMaxOffset($this->maxGapOffset);

        $this->cleanGapsByTimeout($tracking);

        return new StreamPage($events, (string) $tracking);
    }

    private function cleanGapsByTimeout(GapAwarePosition $tracking): void
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

        // Query interleaved events in the gap range
        $interleavedEvents = $this->connection->executeQuery(<<<SQL
            SELECT no, created_at
                FROM {$this->proophStreamTable}
                WHERE no >= :minPosition and no <= :maxPosition
            ORDER BY no
            LIMIT 100
            SQL, [
            'minPosition' => $minGap,
            'maxPosition' => $maxGap + 1,
        ])->iterateAssociative();

        $timestampThreshold = $this->clock->now()->sub($this->gapTimeout)->unixTime()->inSeconds();

        // Find the highest position with timestamp < timeThreshold
        $cutoffPosition = $minGap; // default: keep all gaps
        foreach ($interleavedEvents as $event) {
            $interleavedEventPosition = $event['no'];
            $timestamp = $this->getTimestamp($event['created_at']);

            if ($timestamp > $timestampThreshold) {
                // Event is recent, do not remove any gaps below this position
                break;
            }
            if (in_array($interleavedEventPosition, $gaps, true)) {
                // This position is a gap that could be filled, stop cleaning
                break;
            }
            if ($timestamp < $timestampThreshold && $interleavedEventPosition > $cutoffPosition) {
                $cutoffPosition = $interleavedEventPosition + 1; // Remove gaps below this position
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
