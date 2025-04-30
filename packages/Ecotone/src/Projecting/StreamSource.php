<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

interface StreamSource
{
    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage;
}