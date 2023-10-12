<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;

class RepositoryStorage
{
    /**
     * @var array<EventSourcedRepository|StandardRepository|RepositoryBuilder> $aggregateRepositories
     */
    private array $aggregateRepositories;

    public function __construct(private string $aggregateClassName, private bool $isEventSourcedAggregate, private ChannelResolver $channelResolver, private ReferenceSearchService $referenceSearchService, array $aggregateRepositoryReferenceNames)
    {
        $this->aggregateRepositories = array_values($aggregateRepositoryReferenceNames);
    }

    public function getRepository(): EventSourcedRepository|StandardRepository
    {
        if (count($this->aggregateRepositories) === 1) {
            /** @var EventSourcedRepository|StandardRepository|RepositoryBuilder $repository */
            $repository = $this->aggregateRepositories[0];
            if ($this->isEventSourced($repository) && ! $this->isEventSourcedAggregate) {
                throw InvalidArgumentException::create("There is only one repository registered. For event sourcing usage, however aggregate {$this->aggregateClassName} is not event sourced. If it should be event sourced change attribute to " . EventSourcingAggregate::class);
            } elseif (! $this->isEventSourced($repository) && $this->isEventSourcedAggregate) {
                throw InvalidArgumentException::create("There is only one repository registered. For standard aggregate usage, however aggregate {$this->aggregateClassName} is event sourced. If it should be standard change attribute to " . Aggregate::class);
            }

            return $this->returnRepository($repository);
        }

        if (count($this->aggregateRepositories) === 2) {
            $repositoryOne = $this->aggregateRepositories[0];
            $repositoryTwo = $this->aggregateRepositories[1];

            $repositoryOneIsEventSourced = $this->isEventSourced($repositoryOne);
            $repositoryTwoIsEventSourced = $this->isEventSourced($repositoryTwo);

            if (
                ($repositoryOneIsEventSourced && ! $repositoryTwoIsEventSourced)
                ||
                (! $repositoryOneIsEventSourced && $repositoryTwoIsEventSourced)
            ) {
                if ($this->isEventSourcedAggregate) {
                    return $this->returnRepository($repositoryOneIsEventSourced ? $repositoryOne : $repositoryTwo);
                }

                return $this->returnRepository($repositoryOneIsEventSourced ? $repositoryTwo : $repositoryOne);
            }
        }

        foreach ($this->aggregateRepositories as $repository) {
            if ($repository->canHandle($this->aggregateClassName)) {
                return $this->returnRepository($repository);
            }
        }

        throw InvalidArgumentException::create('There is no repository available for aggregate: ' . $this->aggregateClassName);
    }

    private function returnRepository(EventSourcedRepository|StandardRepository|RepositoryBuilder $repository): EventSourcedRepository|StandardRepository
    {
        if ($repository instanceof RepositoryBuilder) {
            $repository = $repository->build($this->channelResolver, $this->referenceSearchService);
        }

        if ($this->isEventSourcedAggregate) {
            Assert::isTrue($this->isEventSourced($repository), 'Registered standard repository for event sourced aggregate ' . $this->aggregateClassName);
        }
        if (! $this->isEventSourcedAggregate) {
            Assert::isTrue(! $this->isEventSourced($repository), 'Registered event sourced repository for standard aggregate ' . $this->aggregateClassName);
        }

        return $repository;
    }

    private function isEventSourced(EventSourcedRepository|StandardRepository|RepositoryBuilder $repository): bool
    {
        if ($repository instanceof RepositoryBuilder) {
            return $repository->isEventSourced();
        }

        return $repository instanceof EventSourcedRepository;
    }
}
