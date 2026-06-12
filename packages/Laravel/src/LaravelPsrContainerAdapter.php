<?php

declare(strict_types=1);

namespace Ecotone\Laravel;

use function class_exists;

use Illuminate\Contracts\Container\Container;
use Psr\Container\ContainerInterface;

/**
 * licence Apache-2.0
 */
final class LaravelPsrContainerAdapter implements ContainerInterface
{
    public function __construct(private Container $app)
    {
    }

    public function get(string $id): mixed
    {
        return $this->app->make($id);
    }

    public function has(string $id): bool
    {
        return $this->app->has($id) || class_exists($id);
    }
}
