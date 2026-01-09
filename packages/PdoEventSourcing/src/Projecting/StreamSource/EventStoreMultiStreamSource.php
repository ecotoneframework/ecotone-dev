<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;

class EventStoreMultiStreamSource implements StreamSource
{
    /**
     * @param array<string, EventStoreGlobalStreamSource> $sources map of logical stream name => stream source
     */
    public function __construct(
        private array $sources,
    ) {
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        $positions = $this->decodePositions($lastPosition);

        $orderIndex = [];
        $i = 0;
        $newPositions = [];
        $all = [];
        foreach ($this->sources as $stream => $source) {
            $orderIndex[$stream] = $i++;

            $limit = (int)ceil($count / max(1, count($this->sources))) + 5;

            $page = $source->load($positions[$stream] ?? null, $limit, $partitionKey);

            $newPositions[$stream] = $page->lastPosition;
            foreach ($page->events as $event) {
                $all[] = [$stream, $event];
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

        return new StreamPage($events, $this->encodePositions($newPositions));
    }

    /**
     * Encodes map as: stream=position:g1,g2;stream2=position:...;
     */
    private function encodePositions(array $positions): string
    {
        $encoded = '';
        foreach ($positions as $stream => $pos) {
            $encoded .= "$stream=$pos;";
        }
        return $encoded;
    }

    /**
     * Decodes the map encoded by encodePositions.
     * Returns array<string,string> key is stream name, value is position (opaque string)
     */
    private function decodePositions(?string $position): array
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
}
