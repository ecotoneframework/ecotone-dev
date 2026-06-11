<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use const DIRECTORY_SEPARATOR;

use Tempest\Console\ConsoleCommand;
use Tempest\Console\ConsoleConfig;
use Tempest\Container\Container;
use Tempest\Discovery\Discovery;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Discovery\IsDiscovery;
use Tempest\Reflection\ClassReflector;

/**
 * licence Apache-2.0
 */
final class EcotoneConsoleCommandDiscovery implements Discovery
{
    use IsDiscovery;

    public function __construct(
        private readonly Container $container,
        private readonly ConsoleConfig $consoleConfig,
    ) {
    }

    public function discover(DiscoveryLocation $location, ClassReflector $class): void
    {
    }

    public function apply(): void
    {
        if (MessagingSystemInitializer::getRegisteredCommands() === null) {
            if (! $this->container->has(EcotoneConfig::class)) {
                return;
            }

            (new MessagingSystemInitializer())->initialize($this->container);
        }

        $commands = MessagingSystemInitializer::getRegisteredCommands();

        if ($commands === null) {
            return;
        }

        if ($commands === []) {
            return;
        }

        $outputDirectory = MessagingSystemInitializer::getProxyDirectory()
            ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest_console_proxies';

        $generator = new ConsoleCommandProxyGenerator();
        $generatedFiles = $generator->generate($commands, $outputDirectory, MessagingSystemInitializer::getConfigHash());

        foreach ($generatedFiles as $file) {
            require_once $file;
        }

        foreach ($commands as $commandConfiguration) {
            $className = 'Ecotone\\Tempest\\Generated\\' . $this->buildClassName($commandConfiguration->getName());

            if (! class_exists($className)) {
                continue;
            }

            $classReflector = new ClassReflector($className);

            foreach ($classReflector->getPublicMethods() as $method) {
                $consoleCommand = $method->getAttribute(ConsoleCommand::class);

                if ($consoleCommand === null) {
                    continue;
                }

                $this->consoleConfig->addCommand($method, $consoleCommand);
            }
        }
    }

    private function buildClassName(string $commandName): string
    {
        return 'EcotoneConsoleCommand_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $commandName);
    }
}
