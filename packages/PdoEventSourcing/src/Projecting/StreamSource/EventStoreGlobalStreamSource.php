<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Prooph\EventStore\Metadata\FieldType;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;
use RuntimeException;

class EventStoreGlobalStreamSource implements StreamSource
{
    private string $proophStreamTable;
    private Connection $connection;

    public function __construct(
        DbalConnectionFactory|ManagerRegistryConnectionFactory  $connectionFactory,
        private EcotoneClockInterface  $clock,
        private string          $streamName,
        private int             $maxGapOffset = 5_000,
        private ?Duration       $gapTimeout = null,
    ) {
        $this->proophStreamTable = '_' . \sha1($streamName);
        $this->connection = $connectionFactory->createContext()->getDbalConnection();
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::null($partitionKey, 'Partition key is not supported for EventStoreGlobalStreamSource');
        $tracking = GapAwarePosition::fromString($lastPosition);

        $query = $this->connection->executeQuery(<<<SQL
            SELECT no, event_name, payload, metadata, created_at
                FROM {$this->proophStreamTable}
                WHERE no > :position OR no IN (:gaps)
            ORDER BY no
            LIMIT {$count}
            SQL, [
            'position' => $tracking->getPosition(),
            'gaps' => $tracking->getGaps(),
        ], [
            'gaps' => ArrayParameterType::INTEGER,
        ]);

        $events = [];
        $now = $this->clock->now();
        $cutoffTimestamp = $this->gapTimeout ? $now->sub($this->gapTimeout)->getTimestamp() : 0;
        foreach ($query->iterateAssociative() as $event) {
            $position = $event['no'];

            $events[] = Event::createWithType(
                $event['event_name'],
                json_decode($event['payload'], true),
                json_decode($event['metadata'], true),
            );
            $timestamp = $this->getTimestamp($event['created_at']);
            $insertGaps = $timestamp > $cutoffTimestamp;
            $tracking->advanceTo((int) $position, $insertGaps);
        }

        $tracking->cleanByMaxOffset($this->maxGapOffset);

//        $this->cleanGapsByTimeout($tracking);

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
        $interleavedEvents = $this->eventStore->load(
            $this->streamName,
            count: count($gaps),
            metadataMatcher: (new MetadataMatcher())
                ->withMetadataMatch('no', Operator::GREATER_THAN_EQUALS(), $minGap, FieldType::MESSAGE_PROPERTY())
                ->withMetadataMatch('no', Operator::LOWER_THAN_EQUALS(), $maxGap + 1, FieldType::MESSAGE_PROPERTY()),
            deserialize: false,
        );

        $timestampThreshold = $this->clock->now()->sub($this->gapTimeout)->unixTime()->inSeconds();

        // Find the highest position with timestamp < timeThreshold
        $cutoffPosition = $minGap; // default: keep all gaps
        foreach ($interleavedEvents as $event) {
            $metadata = $event->getMetadata();
            $interleavedEventPosition = ((int)$metadata['_position']) ?? null;
            $timestamp = $metadata['timestamp'] ?? null;

            if ($interleavedEventPosition === null || $timestamp === null) {
                break;
            }
            if ($timestamp > $timestampThreshold) {
                // Event is recent, do not remove any gaps below this position
                break;
            }
            if (in_array($interleavedEventPosition, $gaps, true)) {
                // This position is a gap, stop cleaning
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
        return DatePoint::createFromFormat(
            'Y-m-d H:i:s',
            $dateString,
            new DateTimeZone('UTC')
        )->getTimestamp();
    }
}
