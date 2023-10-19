<?php

namespace Ecotone\Laravel;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Modelling\EventSourcedRepository;
use Ecotone\Modelling\RepositoryBuilder;
use Ecotone\Modelling\StandardRepository;
use Exception;

final class EloquentRepositoryBuilder implements RepositoryBuilder
{
    private EloquentRepository $eloquentRepository;

    public function __construct()
    {
        $this->eloquentRepository = new EloquentRepository();
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return $this->eloquentRepository->canHandle($aggregateClassName);
    }

    public function isEventSourced(): bool
    {
        return false;
    }

    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): EventSourcedRepository|StandardRepository
    {
        return $this->eloquentRepository;
    }

    public function compile(ContainerMessagingBuilder $builder): Definition|Reference
    {
        // TODO: Implement compile() method.
        throw new Exception('Not implemented');
    }
}
