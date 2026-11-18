<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Tracing\Config\FrameworkTracing;

final class MessageFlowConsoleCommandsWithoutBuild
{
    public function dump(ConfiguredMessagingSystem $messagingSystem, ConsoleWriter $writer, #[ConsoleParameterOption] string $format = 'mermaid', bool $build = false): void
    {
        if ($build) {
            throw ConfigurationException::create('ecotone:flow:dump --build was asked for what Ecotone registers for the application, which is read only at the framework tracing level. Switch it on with ' . FrameworkTracing::SWITCH_ON . ', or run ecotone:flow:dump without --build for the message flow the application declares.');
        }

        MessageFlowConsoleCommands::write($messagingSystem->getMessageFlowGraph(), $writer, $format, null);
    }
}
