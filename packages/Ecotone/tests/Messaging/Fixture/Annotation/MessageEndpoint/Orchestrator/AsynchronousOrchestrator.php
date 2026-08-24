<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Orchestrator;

/**
 * licence Enterprise
 */
class AsynchronousOrchestrator
{
    private array $executedSteps = [];

    #[Asynchronous('async')]
    #[Orchestrator(inputChannelName: 'asynchronous.workflow', endpointId: 'async-orchestrator')]
    public function simpleWorkflow(): array
    {
        return ['stepA', 'stepB', 'stepC'];
    }

    #[InternalHandler(inputChannelName: 'stepA')]
    public function stepA(array $data): array
    {
        $this->executedSteps[] = 'stepA';
        $data[] = 'stepA';

        return $data;
    }

    #[InternalHandler(inputChannelName: 'stepB')]
    public function stepB(array $data): array
    {
        $this->executedSteps[] = 'stepB';
        $data[] = 'stepB';

        return $data;
    }

    #[InternalHandler(inputChannelName: 'stepC')]
    public function stepC(): array
    {
        $this->executedSteps[] = 'stepC';
        $data[] = 'stepC';

        return $data;
    }

    public function getExecutedSteps(): array
    {
        return $this->executedSteps;
    }
}
