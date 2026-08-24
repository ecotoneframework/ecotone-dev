<?php

namespace Test\Ecotone\Lite;

use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Api\TestConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\Order\ChannelConfiguration;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class EcotoneLiteTest extends TestCase
{
    public function test_it_can_run_console_command(): void
    {
        $ecotone = EcotoneLite::bootstrap(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders'))
        );

        $ecotone->runConsoleCommand('ecotone:list', []);
        $this->expectNotToPerformAssertions();
    }

    public function test_with_spying_on_multiple_channels(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [],
            testConfiguration: TestConfiguration::createWithDefaults()
                ->withSpyOnChannel('async1')
                ->withSpyOnChannel('async2'),
            configuration: ServiceConfiguration::createWithDefaults()->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('async1'))->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('async2'))
        );

        $ecotoneLite->sendDirectToChannel('async1', 'test1');
        $ecotoneLite->sendDirectToChannel('async2', 'test2');

        $this->assertEquals(
            [
                'test1',
            ],
            $ecotoneLite->getRecordedMessagePayloadsFrom('async1')
        );
        $this->assertEquals(
            [
                'test2',
            ],
            $ecotoneLite->getRecordedMessagePayloadsFrom('async2')
        );
    }

    public function test_handling_closure_configuration_variables(): void
    {
        $ecotone = EcotoneLite::bootstrap(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            configurationVariables: [
                'name' => fn () => 'Name',
            ]
        );

        $this->assertEquals(
            ConsoleCommandResultSet::create(['Name'], [['orders']]),
            $ecotone->runConsoleCommand('ecotone:list', [])
        );
    }

    public function test_not_overriding_pcntl_handler_during_tests(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is not loaded');
        }

        $ecotone = EcotoneLite::bootstrapFlowTesting(configuration: ServiceConfiguration::createWithDefaults()->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('async')));

        $handler = pcntl_signal_get_handler(SIGINT);

        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(
            $handler,
            pcntl_signal_get_handler(SIGINT),
            'PCNTL signal handler was changed during test execution after running consumer'
        );
    }
}
