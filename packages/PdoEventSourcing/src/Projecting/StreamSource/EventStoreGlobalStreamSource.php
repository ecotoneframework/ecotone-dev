<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Prooph\EventStore\Metadata\FieldType;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;

class EventStoreGlobalStreamSource implements StreamSource
{
    public function __construct(
        private EventStore      $eventStore,
        private string          $streamName,
        private int             $maxGapOffset = 5_000,
        private ?int            $gapTimeoutMs = null,
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

        foreach ($allEvents as $event) {
            $position = $event->getMetadata()['_position'] ?? throw new \RuntimeException('Event does not have a position');
            $tracking->advanceTo((int) $position);
        }

        $tracking->cleanByMaxOffset($this->maxGapOffset);

        // Apply gap timeout cleaning if configured
        if ($this->gapTimeoutMs !== null && !empty($tracking->getGaps())) {
            $this->cleanGapsByTimeout($tracking);
        }

        return new StreamPage($allEvents, (string) $tracking);
    }

    private function cleanGapsByTimeout(GapAwarePosition $tracking): void
    {
        $gaps = $tracking->getGaps();
        if (empty($gaps)) {
            return;
        }

        $minGap = min($gaps);
        $maxGap = max($gaps);
        
        // Query interleaved events in the gap range
        $interleavedEvents = $this->eventStore->load(
            $this->streamName,
            metadataMatcher: (new MetadataMatcher())
                ->withMetadataMatch('no', Operator::GREATER_THAN_EQUALS(), $minGap, FieldType::MESSAGE_PROPERTY())
                ->withMetadataMatch('no', Operator::LOWER_THAN_EQUALS(), $maxGap, FieldType::MESSAGE_PROPERTY()),
            deserialize: false,
        );

        $nowMs = (int) floor(microtime(true) * 1000);
        $timeThreshold = $nowMs - $this->gapTimeoutMs;
        
        // Find the highest position with timestamp < timeThreshold
        $cutoffPosition = $minGap; // default: keep all gaps
        foreach ($interleavedEvents as $event) {
            $metadata = $event->getMetadata();
            $position = $metadata['_position'] ?? null;
            $timestamp = $metadata['timestamp'] ?? null;
            
            if ($position !== null && $timestamp !== null) {
                // Convert timestamp to milliseconds if needed
                $timestampMs = is_int($timestamp) && $timestamp > 1e10 
                    ? $timestamp 
                    : (int) $timestamp * 1000;
                    
                if ($timestampMs < $timeThreshold && $position > $cutoffPosition) {
                    $cutoffPosition = $position + 1; // Remove gaps below this position
                }
            }
        }

        $tracking->cutoffGapsBelow($cutoffPosition);
    }
}