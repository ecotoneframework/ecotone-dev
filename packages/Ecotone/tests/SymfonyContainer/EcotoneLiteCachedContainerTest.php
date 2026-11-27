<?php

declare(strict_types=1);

namespace Test\Ecotone\SymfonyContainer;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\OneTimeCommand\OneTimeWithResultExample;

/**
 * licence Apache-2.0
 * @internal
 */
class EcotoneLiteCachedContainerTest extends TestCase
{
    public function test_bootstrap_with_cache_dumps_symfony_container_and_warm_boot_uses_it(): void
    {
        $cacheDirectory = sys_get_temp_dir() . '/ecotone_lite_dumped_container/' . uniqid('', true);
        $handler = new CachedCommandHandlerService();

        $messagingSystem = EcotoneLite::bootstrap(
            [],
            [CachedCommandHandlerService::class => $handler],
            enabledModules: [],
            useCachedVersion: true,
            cacheDirectoryPath: $cacheDirectory,
        );
        $messagingSystem->getCommandBus()->sendWithRouting('cache.command', 'first');

        self::assertNotEmpty(glob($cacheDirectory . '/ecotone/*/ecotone_container.php'));

        $warmBootedMessagingSystem = EcotoneLite::bootstrap(
            [],
            [CachedCommandHandlerService::class => $handler],
            enabledModules: [],
            useCachedVersion: true,
            cacheDirectoryPath: $cacheDirectory,
        );
        $warmBootedMessagingSystem->getCommandBus()->sendWithRouting('cache.command', 'second');

        self::assertSame(['first', 'second'], $handler->received);
    }

    public function test_cache_directory_that_cannot_be_created_is_refused_with_the_ways_out(): void
    {
        $fileInPlaceOfDirectory = $this->uniqueCacheDirectory() . '/file';
        mkdir(dirname($fileInPlaceOfDirectory), 0777, true);
        touch($fileInPlaceOfDirectory);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches(
            '#^Ecotone cache directory ' . preg_quote($fileInPlaceOfDirectory, '#') . '/ecotone/[0-9a-f]+ could not be created\. Create it, make its parent directory writable for the user running the application, ' . self::ANOTHER_CACHE_DIRECTORY . '$#',
        );

        $this->bootWithCacheIn($fileInPlaceOfDirectory, new CachedCommandHandlerService());
    }

    public function test_cache_directory_that_cannot_be_written_to_is_refused_with_the_ways_out(): void
    {
        $cacheDirectory = $this->uniqueCacheDirectory();
        $containerDirectory = $this->emptiedContainerDirectoryOfABootedApplication($cacheDirectory);
        chmod($containerDirectory, 0555);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches(
            '#^Ecotone cache file ' . preg_quote($containerDirectory, '#') . '/EcotoneCachedContainer_[0-9a-f]{32}\.php could not be written\. Make the directory ' . preg_quote($containerDirectory, '#') . ' writable for the user running the application and check that the disk is not full, ' . self::ANOTHER_CACHE_DIRECTORY . '$#',
        );

        $this->bootWithCacheIn($cacheDirectory, new CachedCommandHandlerService());
    }

    public function test_cache_file_that_cannot_be_replaced_is_refused_with_the_ways_out(): void
    {
        $cacheDirectory = $this->uniqueCacheDirectory();
        $containerDirectory = $this->emptiedContainerDirectoryOfABootedApplication($cacheDirectory);
        $directoryInPlaceOfFile = $containerDirectory . '/ecotone_container.php';
        mkdir($directoryInPlaceOfFile);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches(
            '#^Ecotone cache file ' . preg_quote($directoryInPlaceOfFile, '#') . ' could not be put in place, because ' . preg_quote($directoryInPlaceOfFile, '#') . ' cannot be replaced\. Remove what is stored under that path and make the directory ' . preg_quote($containerDirectory, '#') . ' writable for the user running the application, ' . self::ANOTHER_CACHE_DIRECTORY . '$#',
        );

        $this->bootWithCacheIn($cacheDirectory, new CachedCommandHandlerService());
    }

    public function test_application_boots_on_a_cold_cache_when_the_dump_lock_cannot_be_taken(): void
    {
        $cacheDirectory = $this->uniqueCacheDirectory();
        $containerDirectory = $this->emptiedContainerDirectoryOfABootedApplication($cacheDirectory);
        mkdir($containerDirectory . '/ecotone_container.lock');
        $handler = new CachedCommandHandlerService();

        $this->bootWithCacheIn($cacheDirectory, $handler)->getCommandBus()->sendWithRouting('cache.command', 'handled');

        self::assertSame(['handled'], $handler->received);
    }

    private const ANOTHER_CACHE_DIRECTORY = 'or boot Ecotone with a cache directory that user can write to: `cacheDirectoryPath` of EcotoneLite::bootstrap\(\), `kernel\.build_dir` in Symfony, the storage path in Laravel, the system temporary directory in Tempest\.';

    private function emptiedContainerDirectoryOfABootedApplication(string $cacheDirectory): string
    {
        $this->bootWithCacheIn($cacheDirectory, new CachedCommandHandlerService());
        [$containerDirectory] = glob($cacheDirectory . '/ecotone/*');
        array_map('unlink', array_filter(glob($containerDirectory . '/*'), 'is_file'));

        return $containerDirectory;
    }

    private function bootWithCacheIn(string $cacheDirectory, CachedCommandHandlerService $handler): ConfiguredMessagingSystem
    {
        return EcotoneLite::bootstrap(
            classesToResolve: [],
            containerOrAvailableServices: [CachedCommandHandlerService::class => $handler],
            enabledModules: [],
            useCachedVersion: true,
            cacheDirectoryPath: $cacheDirectory,
        );
    }

    private function uniqueCacheDirectory(): string
    {
        return sys_get_temp_dir() . '/ecotone_lite_dumped_container/' . uniqid('', true);
    }

    public function test_registered_console_commands_are_available_as_container_parameter(): void
    {
        $messagingSystem = EcotoneLite::bootstrap(
            [],
            [OneTimeWithResultExample::class => new OneTimeWithResultExample()],
            enabledModules: [],
        );

        $container = $messagingSystem->getServiceFromContainer(ContainerInterface::class);

        $consoleCommands = $container->getRegisteredConsoleCommands();
        self::assertContains('doSomething', array_map(fn ($command) => $command->getName(), $consoleCommands));
    }

    public function test_gateway_bridges_can_be_registered_into_framework_container(): void
    {
        $messagingSystem = EcotoneLite::bootstrap(
            [],
            [CachedCommandHandlerService::class => new CachedCommandHandlerService()],
            enabledModules: [],
        );
        $container = $messagingSystem->getServiceFromContainer(ContainerInterface::class);

        $registeredBridges = [];
        $container->registerBridgesInto(function (string $referenceName, string $interfaceName, callable $factory) use (&$registeredBridges) {
            $registeredBridges[$referenceName] = [$interfaceName, $factory];
        });

        self::assertArrayHasKey(ConfiguredMessagingSystem::class, $registeredBridges);
        self::assertArrayHasKey(CommandBus::class, $registeredBridges);
        self::assertSame(CommandBus::class, $registeredBridges[CommandBus::class][0]);
        self::assertInstanceOf(CommandBus::class, $registeredBridges[CommandBus::class][1]());
    }
}

/**
 * licence Apache-2.0
 */
class CachedCommandHandlerService
{
    public array $received = [];

    #[CommandHandler('cache.command')]
    public function handle(#[Payload] string $payload): void
    {
        $this->received[] = $payload;
    }
}
