<?php

declare(strict_types=1);

namespace Test\Ecotone\SymfonyContainer;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\CommandHandler;
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
        $configuration = ServiceConfiguration::createWithDefaults()
            ->withCacheDirectoryPath($cacheDirectory)
            ->withSkippedModulePackageNames(ModulePackageList::allPackages());
        $handler = new CachedCommandHandlerService();

        $messagingSystem = EcotoneLite::bootstrap(
            [CachedCommandHandlerService::class],
            [CachedCommandHandlerService::class => $handler],
            $configuration,
            useCachedVersion: true,
        );
        $messagingSystem->getCommandBus()->sendWithRouting('cache.command', 'first');

        self::assertNotEmpty(glob($cacheDirectory . '/ecotone/*/ecotone_container.php'));

        $warmBootedMessagingSystem = EcotoneLite::bootstrap(
            [CachedCommandHandlerService::class],
            [CachedCommandHandlerService::class => $handler],
            $configuration,
            useCachedVersion: true,
        );
        $warmBootedMessagingSystem->getCommandBus()->sendWithRouting('cache.command', 'second');

        self::assertSame(['first', 'second'], $handler->received);
    }

    public function test_registered_console_commands_are_available_as_container_parameter(): void
    {
        $messagingSystem = EcotoneLite::bootstrap(
            [OneTimeWithResultExample::class],
            [OneTimeWithResultExample::class => new OneTimeWithResultExample()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
        );

        $container = $messagingSystem->getServiceFromContainer(ContainerInterface::class);

        $consoleCommands = unserialize($container->getParameter('ecotone.console_commands'));
        self::assertContains('doSomething', array_map(fn ($command) => $command->getName(), $consoleCommands));
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
