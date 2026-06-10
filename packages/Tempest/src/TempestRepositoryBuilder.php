<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Modelling\RepositoryBuilder;

/**
 * licence Apache-2.0
 */
final class TempestRepositoryBuilder implements RepositoryBuilder
{
    private TempestRepository $tempestRepository;

    public function __construct()
    {
        $this->tempestRepository = new TempestRepository();
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return $this->tempestRepository->canHandle($aggregateClassName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(TempestRepository::class);
    }
}
