<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Enterprise
 */
class Orchestrator
{
    public function __construct(
        private string $inputChannelName,
        private string $endpointId = '',
    ) {
    }

    public function getInputChannelName(): string
    {
        return $this->inputChannelName;
    }

    public function getEndpointId(): string
    {
        return $this->endpointId;
    }
}
