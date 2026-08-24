<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Execution;

use Ecotone\Api\BusinessMethod;

interface AuthorizationProcess
{
    #[BusinessMethod('start.authorization')]
    public function start(string $data): string;
}
