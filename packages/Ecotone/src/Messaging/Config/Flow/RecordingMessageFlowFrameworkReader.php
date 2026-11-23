<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\MessageFlowGraph;

final class RecordingMessageFlowFrameworkReader implements MessageFlowFrameworkReader
{
    public function __construct(private MessageFlowFrameworkGraphBuilder $frameworkGraph, private MessageFlowFrameworkLedger $frameworkLedger)
    {
    }

    public function wiring(): MessageFlowGraph
    {
        return $this->frameworkGraph->build();
    }

    public function framework(): array
    {
        return $this->frameworkLedger->toArray();
    }
}
