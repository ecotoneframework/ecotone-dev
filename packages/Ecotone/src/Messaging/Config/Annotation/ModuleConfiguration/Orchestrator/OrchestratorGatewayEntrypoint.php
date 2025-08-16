<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration\Orchestrator;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;

final class OrchestratorGatewayEntrypoint
{
    public function handle(array $routingSlip): array
    {
        foreach ($routingSlip as $index => $channelName) {
            Assert::isTrue(is_string($channelName), "Orchestrator returned array must contain only strings, but found " . gettype($channelName) . " at index {$index}");
        }

        return $routingSlip;
    }
}