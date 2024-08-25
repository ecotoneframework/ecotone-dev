<?php

namespace Test;

use Ecotone\Lite\Test\MessagingTestSupport;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Monolog\Handler\TestHandler;
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
        self::assertEquals('test', $logRecord->message);
        self::assertEquals('ecotone', $logRecord->channel);
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
