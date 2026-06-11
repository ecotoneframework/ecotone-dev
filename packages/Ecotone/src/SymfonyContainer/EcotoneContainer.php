<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;

/**
 * licence Apache-2.0
 */
final class EcotoneContainer implements ContainerInterface
{
    public function __construct(
        private SymfonyContainerInterface $container,
        private ContainerInterface $externalContainer,
    ) {
    }

    public function get(string $id): mixed
    {
        if ($this->container->has($id)) {
            return $this->container->get($id);
        }

        return ExternalReferenceResolver::resolve($this->externalContainer, $id, ContainerImplementation::EXCEPTION_ON_INVALID_REFERENCE);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id) || $this->externalContainer->has($id);
    }

    public function set(string $id, mixed $service): void
    {
        $this->container->set($id, $service);
    }
}
