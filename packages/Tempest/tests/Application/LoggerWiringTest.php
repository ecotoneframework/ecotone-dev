<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\QueryBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Tempest\Core\FrameworkKernel;
use Tempest\Core\KernelEvent;
use Tempest\Discovery\AutoloadDiscoveryLocations;
use Tempest\Discovery\Composer;
use Tempest\Discovery\DiscoveryConfig;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Framework\Testing\IntegrationTest;
use Tempest\Log\Config\MultipleChannelsLogConfig;
use Tempest\Log\LogChannel;
use Test\Ecotone\Tempest\TempestTestPaths;

/**
 * licence Apache-2.0
 * @internal
 */
final class LoggerWiringTest extends IntegrationTest
{
    protected string $root = '';

    private TestHandler $logHandler;

    public function setUp(): void
    {
        EcotoneServiceInitializer::clearCache();
        $this->internalStorage = '/tmp/ecotone_tempest_logger_test_' . getmypid();
        $this->logHandler = new TestHandler();
        $this->setupKernel();
    }

    public function setupKernel(): self
    {
        if ($this->root === '') {
            $this->root = TempestTestPaths::appRoot();
        }

        EcotoneServiceInitializer::clearCache();

        $allLocations = [
            new DiscoveryLocation('Ecotone\\Tempest\\', TempestTestPaths::srcPath()),
            new DiscoveryLocation('Test\\Ecotone\\Tempest\\Fixture\\', TempestTestPaths::fixturePath()),
        ];

        $kernel = new FrameworkKernel(
            root: $this->root,
            discoveryLocations: $allLocations,
            internalStorage: $this->internalStorage,
        );

        $kernel->registerKernel()
               ->validateRoot()
               ->loadEnv()
               ->registerEmergencyExceptionHandler()
               ->registerShutdownFunction()
               ->registerInternalStorage()
               ->loadComposer();

        $this->injectDiscoveryConfig($kernel, $allLocations);

        $kernel->container->config(new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\ExpressionLanguage\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
        ));

        $captureHandler = $this->logHandler;
        $captureChannel = new class ($captureHandler) implements LogChannel {
            public function __construct(private TestHandler $handler)
            {
            }

            public function getHandlers(Level $level): array
            {
                return [$this->handler];
            }

            public function getProcessors(): array
            {
                return [];
            }
        };

        $kernel->loadConfig();

        $kernel->container->config(new MultipleChannelsLogConfig(
            channels: [$captureChannel],
            prefix: 'test',
        ));

        $kernel->bootDiscovery()
               ->registerExceptionHandler()
               ->event(KernelEvent::BOOTED);

        $this->kernel = $kernel;
        $this->container = $kernel->container;

        return $this;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
        restore_error_handler();
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
    }

    private function injectDiscoveryConfig(FrameworkKernel $kernel, array $extraLocations): void
    {
        $testAppComposer = (new Composer($this->root))->load();
        $testAppComposer->namespaces = [];

        $autoloadLocations = (new AutoloadDiscoveryLocations(
            rootPath: TempestTestPaths::discoveryRoot(),
            composer: $testAppComposer,
        ))();

        $discoveryConfig = $kernel->container->get(DiscoveryConfig::class);
        $discoveryConfig->locations = [...$extraLocations, ...$autoloadLocations];

        $kernel->container->config($discoveryConfig);
        $kernel->discoveryConfig = $discoveryConfig;
    }

    public function test_ecotone_logs_flow_through_tempest_logger_after_wiring(): void
    {
        $queryBus = $this->container->get(QueryBus::class);

        $queryBus->sendWithRouting('getAmount');

        $this->assertTrue(
            $this->logHandler->hasInfoThatContains('Executing Query Handler'),
            'Expected Ecotone to emit log through the Tempest logger',
        );
    }
}
