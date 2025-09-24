<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Prooph\EventStore\Metadata\FieldType;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;
use RuntimeException;

class EventStoreGlobalStreamSource implements StreamSource
{
    public function __construct(
        private EventStore      $eventStore,
        private EcotoneClockInterface  $clock,
        private string          $streamName,
        private int             $maxGapOffset = 5_000,
        private ?Duration       $gapTimeout = null,
    ) {
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::null($partitionKey, 'Partition key is not supported for EventStoreGlobalStreamSource');
        $tracking = GapAwarePosition::fromString($lastPosition);

        if (count($tracking->getGaps()) === 0) {
            $eventsInGaps = [];
        } else {
            $eventsInGaps = $this->eventStore->load(
                $this->streamName,
                count: count($tracking->getGaps()),
                metadataMatcher: (new MetadataMatcher())
                    ->withMetadataMatch('no', Operator::IN(), $tracking->getGaps(), FieldType::MESSAGE_PROPERTY()),
                deserialize: false,
            );
        }

        $events = $this->eventStore->load(
            $this->streamName,
            $tracking->getPosition() + 1,
            $count,
            deserialize: false,
        );


        $allEvents = [...$eventsInGaps, ...$events];

        $now = $this->clock->now();
        $cutoffTimestamp = $this->gapTimeout ? $now->sub($this->gapTimeout)->getTimestamp() : 0;
        foreach ($allEvents as $event) {
            $position = $event->getMetadata()['_position'] ?? throw new RuntimeException('Event does not have a position');
            $timestamp = $event->getMetadata()['timestamp'] ?? throw new RuntimeException('Event does not have a timestamp');
            $insertGaps = $timestamp > $cutoffTimestamp;
            $tracking->advanceTo((int) $position, $insertGaps);
        }

        $tracking->cleanByMaxOffset($this->maxGapOffset);

        $this->cleanGapsByTimeout($tracking);

        return new StreamPage($allEvents, (string) $tracking);
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
}
