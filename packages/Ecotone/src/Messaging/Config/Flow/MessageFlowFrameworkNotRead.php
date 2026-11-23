<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Config\ConfigurationException;

final class MessageFlowFrameworkNotRead implements MessageFlowFrameworkReader
{
    public function __construct(private string $reason)
    {
    }

    public function wiring(): MessageFlowGraph
    {
        throw $this->refusal();
    }

    public function framework(): array
    {
        throw $this->refusal();
    }

    private function refusal(): ConfigurationException
    {
        return ConfigurationException::create('The framework wiring Ecotone registers for this application was asked for, but ' . $this->reason);
    }
}
