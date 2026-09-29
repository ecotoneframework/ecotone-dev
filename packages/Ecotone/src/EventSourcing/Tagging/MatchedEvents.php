<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use function array_map;
use function array_values;

use Ecotone\Modelling\Event;

use function uasort;

/**
 * licence Enterprise
 */
final class MatchedEvents
{
    /** @var array<string, array{sequence: int, eventNo: int, event: Event, sequencesByTagKey: array<string, int>}> */
    private array $lowestSequenceMatches = [];

    /**
     * @param array<string, int> $sequencesByTagKey
     */
    public function consider(string $stream, int $eventNo, int $sequence, Event $event, array $sequencesByTagKey = []): void
    {
        $reference = $stream . "\0" . $eventNo;
        $known = $this->lowestSequenceMatches[$reference] ?? null;
        $sequencesByTagKey = [...$known['sequencesByTagKey'] ?? [], ...$sequencesByTagKey];

        if ($known !== null && $known['sequence'] <= $sequence) {
            $this->lowestSequenceMatches[$reference]['sequencesByTagKey'] = $sequencesByTagKey;

            return;
        }

        $this->lowestSequenceMatches[$reference] = ['sequence' => $sequence, 'eventNo' => $eventNo, 'event' => $event, 'sequencesByTagKey' => $sequencesByTagKey];
    }

    /**
     * @return Event[]
     */
    public function inSequenceOrder(): array
    {
        $matches = $this->lowestSequenceMatches;
        uasort($matches, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence'] ?: $a['eventNo'] <=> $b['eventNo']);

        return array_values(array_map(
            static fn (array $match): Event => MatchedTagSequences::stampOn($match['event'], $match['sequencesByTagKey']),
            $matches
        ));
    }
}
