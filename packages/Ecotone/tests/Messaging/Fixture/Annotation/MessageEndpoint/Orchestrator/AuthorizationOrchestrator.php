<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator;

use Ecotone\Messaging\Attribute\Orchestrator;

/**
 * licence Enterprise
 */
class AuthorizationOrchestrator
{
    #[Orchestrator(inputChannelName: "start.authorization", endpointId: "auth-orchestrator")]
    public function startAuthorization(): array
    {
        return ["validate", "process", "sendEmail"];
    }
}
