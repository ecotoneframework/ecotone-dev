<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

interface StreamSource
{
    public function load(string $streamName, ?string $lastPosition, int $count): StreamPage;
}