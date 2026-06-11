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
        $normalizedId = ServiceIdNormalizer::normalize($id);
        if ($this->container->has($normalizedId)) {
            return $this->container->get($normalizedId);
        }

        return ExternalReferenceResolver::resolve($this->externalContainer, $id, ContainerImplementation::EXCEPTION_ON_INVALID_REFERENCE);
    }

    public function has(string $id): bool
    {
        return $this->container->has(ServiceIdNormalizer::normalize($id)) || $this->externalContainer->has($id);
    }

    public function set(string $id, mixed $service): void
    {
        $this->container->set(ServiceIdNormalizer::normalize($id), $service);
    }

    public function getParameter(string $name): mixed
    {
        return $this->container->getParameter($name);
    }

    /**
     * @return string[]
     */
    public function getServiceIds(): array
    {
        return $this->container->getServiceIds();
    }
}
