<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\MessageFlowGraph;

final class RecordingMessageFlowBuildReader implements MessageFlowBuildReader
{
    public function __construct(private MessageFlowBuildGraphBuilder $buildGraph, private MessageFlowBuild $messageFlowBuild)
    {
    }

    public function wiring(): MessageFlowGraph
    {
        return $this->buildGraph->build();
    }

    public function build(): array
    {
        return $this->messageFlowBuild->toArray();
    }
}
