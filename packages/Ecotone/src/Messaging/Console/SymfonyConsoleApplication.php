<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Console;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Symfony\Component\Console\Application;

/**
 * licence Apache-2.0
 */
final class SymfonyConsoleApplication
{
    /**
     * @param ConsoleCommandConfiguration[] $consoleCommands
     */
    public static function create(array $consoleCommands, ConsoleCommandRunner $consoleCommandRunner, DelegatingConsoleWriter $consoleWriter): Application
    {
        if (! class_exists(Application::class)) {
            throw ConfigurationException::create('ConfiguredMessagingSystem::console() runs the registered console commands with symfony/console, which is not installed. Install it with `composer require symfony/console`, or run a single command with ConfiguredMessagingSystem::runConsoleCommand().');
        }

        $application = new Application('Ecotone');
        $application->setAutoExit(false);
        $application->addCommands(array_map(
            fn (ConsoleCommandConfiguration $consoleCommand) => new SymfonyConsoleCommand($consoleCommand, $consoleCommandRunner, $consoleWriter),
            $consoleCommands,
        ));

        return $application;
    }
}
