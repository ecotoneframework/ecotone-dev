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
    /** @var array<string, array{tagVersion: int, eventNo: int, event: Event}> */
    private array $lowestTagVersionMatches = [];

    public function consider(string $stream, int $eventNo, int $tagVersion, Event $event): void
    {
        $reference = $stream . "\0" . $eventNo;
        $known = $this->lowestTagVersionMatches[$reference] ?? null;

        if ($known === null || $known['tagVersion'] > $tagVersion) {
            $this->lowestTagVersionMatches[$reference] = ['tagVersion' => $tagVersion, 'eventNo' => $eventNo, 'event' => $event];
        }
    }

    /**
     * @return Event[]
     */
    public function inTagVersionOrder(): array
    {
        $matches = $this->lowestTagVersionMatches;
        uasort($matches, static fn (array $a, array $b): int => $a['tagVersion'] <=> $b['tagVersion'] ?: $a['eventNo'] <=> $b['eventNo']);

        return array_values(array_map(static fn (array $match): Event => $match['event'], $matches));
    }
}
