<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator;

use Ecotone\Messaging\Attribute\Orchestrator;

/**
 * licence Enterprise
 */
class SimpleOrchestrator
{
    #[Orchestrator(inputChannelName: "simple.workflow")]
    public function simpleWorkflow(): array
    {
        return ["step1", "step2", "step3"];
    }

    #[Orchestrator(inputChannelName: "empty.workflow")]
    public function emptyWorkflow(): array
    {
        return [];
    }

    #[Orchestrator(inputChannelName: "single.step")]
    public function singleStep(): array
    {
        return ["only_step"];
    }
}
