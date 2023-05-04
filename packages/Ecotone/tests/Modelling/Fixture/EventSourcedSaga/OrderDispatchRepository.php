<?php

namespace Test\Ecotone\Modelling\Fixture\EventSourcedSaga;

use Ecotone\Modelling\Attribute\Repository;
use Ecotone\Modelling\InMemoryEventSourcedRepository;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\Job;

#[Repository]
class OrderDispatchRepository extends InMemoryEventSourcedRepository
{
    public function __construct()
    {
        parent::__construct([], [OrderDispatch::class]);
    }
}
