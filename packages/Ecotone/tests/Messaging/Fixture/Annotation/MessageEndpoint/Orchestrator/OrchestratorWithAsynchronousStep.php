<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Orchestrator;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
class OrchestratorWithAsynchronousStep
{
    private array $executedSteps = [];

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

    #[Asynchronous('async')]
    #[InternalHandler(inputChannelName: 'stepB', endpointId: 'async-step')]
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
