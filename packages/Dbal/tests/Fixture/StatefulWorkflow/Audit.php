<?php

namespace Test\Ecotone\Dbal\Fixture\StatefulWorkflow;

class Audit
{
    public function __construct(
        public string $auditId,
        public ?Certificate $certificate = null,
    ) {
    }
}
