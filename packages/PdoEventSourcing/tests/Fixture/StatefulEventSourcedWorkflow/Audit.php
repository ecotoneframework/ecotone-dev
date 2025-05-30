<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

class Audit
{
    public function __construct(
        public string $auditId,
        public ?Certificate $certificate = null,
    ) {
    }
}
