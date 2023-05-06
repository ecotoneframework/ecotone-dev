<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Ecotone\Messaging\Handler\ReferenceSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SymfonyReferenceSearchService implements ReferenceSearchService
{
    public const REFERENCE_SUFFIX = '-proxy';

    public function __construct(private ContainerInterface $container)
    {
    }

    public function get(string $referenceName): object
    {
        return $this->container->get(self::getServiceNameWithSuffix($referenceName));
    }

    public function has(string $referenceName): bool
    {
        return $this->container->has(self::getServiceNameWithSuffix($referenceName));
    }

    public static function getServiceNameWithSuffix(string $referenceName): string
    {
        return $referenceName . self::REFERENCE_SUFFIX;
    }
}
