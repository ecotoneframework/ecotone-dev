<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Container;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Tempest\Container\Container;

/**
 * licence Apache-2.0
 */
final class TempestContainerAdapter implements ContainerInterface
{
    public function __construct(
        private readonly Container $tempestContainer
    ) {
    }

    public function get(string $id): mixed
    {
        return $this->tempestContainer->get($id);
    }

    public function has(string $id): bool
    {
        return $this->tempestContainer->has($id);
    }
}
