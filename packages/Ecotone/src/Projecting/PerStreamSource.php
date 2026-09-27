<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

interface PerStreamSource extends StreamSource
{
    public function loadStream(string $projectionName, StreamFilter $streamFilter, ?string $lastPosition, int $count): StreamPage;

    /**
     * @return array<string, string> stream name => position within that stream
     */
    public function splitCombinedPositionIntoStreamPositions(string $combinedPosition): array;
}
