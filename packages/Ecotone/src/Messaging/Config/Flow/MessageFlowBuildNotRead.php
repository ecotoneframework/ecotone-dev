<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Tracing\Config\FrameworkTracing;

final class MessageFlowBuildNotRead implements MessageFlowBuildReader
{
    public function wiring(): MessageFlowGraph
    {
        throw self::refusal();
    }

    public function build(): array
    {
        throw self::refusal();
    }

    private static function refusal(): ConfigurationException
    {
        return ConfigurationException::create('The build wiring Ecotone registers for this application was asked for, but it is read only at the framework tracing level. Switch it on with ' . FrameworkTracing::SWITCH_ON . '.');
    }
}
