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
    /** @var array<string, array{sequence: int, eventNo: int, event: Event}> */
    private array $lowestSequenceMatches = [];

    public function consider(string $stream, int $eventNo, int $sequence, Event $event): void
    {
        $reference = $stream . "\0" . $eventNo;
        $known = $this->lowestSequenceMatches[$reference] ?? null;

        if ($known === null || $known['sequence'] > $sequence) {
            $this->lowestSequenceMatches[$reference] = ['sequence' => $sequence, 'eventNo' => $eventNo, 'event' => $event];
        }
    }

    /**
     * @return Event[]
     */
    public function inSequenceOrder(): array
    {
        $matches = $this->lowestSequenceMatches;
        uasort($matches, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence'] ?: $a['eventNo'] <=> $b['eventNo']);

        return array_values(array_map(static fn (array $match): Event => $match['event'], $matches));
    }
}
