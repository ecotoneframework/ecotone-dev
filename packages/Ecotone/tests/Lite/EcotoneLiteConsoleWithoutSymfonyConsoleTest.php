<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class EcotoneLiteConsoleWithoutSymfonyConsoleTest extends TestCase
{
    public function test_the_console_is_refused_when_symfony_console_is_not_installed(): void
    {
        $commands = new class () {
            #[ConsoleCommand('app:orders:ship', 'Ships the given order')]
            public function ship(): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [$commands::class],
            containerOrAvailableServices: [$commands],
            enabledModules: [],
        );
        self::hideSymfonyConsoleFromTheAutoloader();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('ConfiguredMessagingSystem::console() runs the registered console commands with symfony/console, which is not installed. Install it with `composer require symfony/console`, or run a single command with ConfiguredMessagingSystem::runConsoleCommand().');

        $ecotone->console();
    }

    private static function hideSymfonyConsoleFromTheAutoloader(): void
    {
        foreach (spl_autoload_functions() as $autoloader) {
            spl_autoload_unregister($autoloader);
            spl_autoload_register(static function (string $class) use ($autoloader): void {
                if (! str_starts_with($class, 'Symfony\\Component\\Console\\')) {
                    $autoloader($class);
                }
            });
        }
    }
}
