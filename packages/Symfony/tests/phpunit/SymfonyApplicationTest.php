<?php

namespace Test;

use Ecotone\Lite\Test\MessagingTestSupport;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\SymfonyConfigurationVariableService;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class SymfonyApplicationTest extends KernelTestCase
{
    public function test_it_boots_kernel_with_test_support(): void
    {
        self::bootKernel([
            'environment' => 'test',
        ]);
        self::assertInstanceOf(
            MessagingTestSupport::class,
            self::getMessagingTestSupport()
        );
    }

    public function test_it_writes_logs_to_ecotone_channel(): void
    {
        self::bootKernel([
            'environment' => 'test_monolog_integration',
        ]);

        /** @var TestHandler $testHandler */
        $testHandler = self::getContainer()->get('monolog.handler.testing');

        $ecotoneInternalLogger = self::getContainer()->get(LoggingGateway::class);

        $ecotoneInternalLogger->info('test');

        $logRecord = $testHandler->getRecords()[0];
        self::assertCount(1, $testHandler->getRecords());

        if ($logRecord instanceof LogRecord) {
            self::assertEquals('test', $logRecord->message);
            self::assertEquals('ecotone', $logRecord->channel);
        } else {
            // For compatibility with Monolog 2.0
            self::assertEquals('test', $logRecord['message']);
            self::assertEquals('ecotone', $logRecord['channel']);
        }
    }

    public function test_configuration_variable_service(): void
    {
        $testVar = 'foo';
        putenv('TEST_VAR=' . $testVar);

        $kernel = new Kernel('dev', true);
        $containerBuilder = $kernel->createContainerBuilder();

        $service = new SymfonyConfigurationVariableService($containerBuilder);
        self::assertEquals($testVar, $service->getByName('test_var'));

        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $service = new SymfonyConfigurationVariableService($container);
        self::assertEquals($testVar, $service->getByName('test_var'));
    }

    protected static function getMessagingSystem(): ConfiguredMessagingSystem
    {
        return static::getContainer()->get(ConfiguredMessagingSystem::class);
    }

    protected static function getMessagingTestSupport(): MessagingTestSupport
    {
        return static::getMessagingSystem()->getGatewayByName(MessagingTestSupport::class);
    }
}
