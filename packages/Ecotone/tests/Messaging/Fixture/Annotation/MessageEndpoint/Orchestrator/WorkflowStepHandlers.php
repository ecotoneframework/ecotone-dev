<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator;

use Ecotone\Messaging\Attribute\InternalHandler;

/**
 * licence Enterprise
 */
class WorkflowStepHandlers
{
    private array $executedSteps = [];

    #[InternalHandler(inputChannelName: "validate")]
    public function validate(string $data): string
    {
        $this->executedSteps[] = "validate";
        return "validated: " . $data;
    }

    #[InternalHandler(inputChannelName: "process")]
    public function process(string $data): string
    {
        $this->executedSteps[] = "process";
        return "processed: " . $data;
    }

    #[InternalHandler(inputChannelName: "sendEmail")]
    public function sendEmail(string $data): string
    {
        $this->executedSteps[] = "sendEmail";
        return "email sent for: " . $data;
    }

    #[InternalHandler(inputChannelName: "only_step")]
    public function onlyStep(string $data): string
    {
        $this->executedSteps[] = "only_step";
        return "validated: " . $data;
    }

    public function getExecutedSteps(): array
    {
        return $this->executedSteps;
    }
}
