<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Tempest\Container\Container;
use Throwable;

/**
 * licence Apache-2.0
 */
final class TempestPsrContainerAdapter implements ContainerInterface
{
    public function __construct(private Container $container)
    {
    }

    public function get(string $id): mixed
    {
        return $this->container->get($this->mapServiceId($id));
    }

    public function has(string $id): bool
    {
        $id = $this->mapServiceId($id);
        if ($this->container->has($id)) {
            return true;
        }

        if (! class_exists($id) && ! interface_exists($id)) {
            return false;
        }

        // Tempest's Container::has() does not account for services provided by
        // Initializer / DynamicInitializer classes (e.g. Tempest\Mail\Mailer).
        // Resolving is the only reliable answer; a resolved singleton is cached
        // by the container, so the follow-up get() does no extra work.
        try {
            $this->container->get($id);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function mapServiceId(string $id): string
    {
        return $id === 'logger' ? LoggerInterface::class : $id;
    }
}
