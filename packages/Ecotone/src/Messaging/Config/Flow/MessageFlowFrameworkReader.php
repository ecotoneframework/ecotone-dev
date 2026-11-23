<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\MessageFlowGraph;

interface MessageFlowFrameworkReader
{
    public function wiring(): MessageFlowGraph;

    /**
     * @return array<string, mixed>
     */
    public function framework(): array;
}
