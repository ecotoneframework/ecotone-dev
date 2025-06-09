<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

class Certificate
{
    public function __construct(public string $certificateId)
    {
    }
}
