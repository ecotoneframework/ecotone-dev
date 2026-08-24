<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Api\Gateway;

use Psr\Container\ContainerInterface;

interface ProjectionRegistry extends ContainerInterface
{
    public function get(string $id): ProjectingManager;
    /** @return iterable<string> */
    public function projectionNames(): iterable;
}
