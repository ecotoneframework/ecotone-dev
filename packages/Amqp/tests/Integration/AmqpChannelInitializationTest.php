<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Messaging\Channel\Manager\ChannelInitializationConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Exception;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * Tests for AMQP channel initialization with manual setup.
 *
 * licence Apache-2.0
 * @internal
 */
final class AmqpChannelInitializationTest extends AmqpMessagingTestCase
{
    private const TEST_CHANNEL_NAME = 'test_channel_init';
    private const TEST_CHANNEL_NAME_2 = 'test_channel_init_2';
    private const TEST_CHANNEL_NAME_3 = 'test_channel_init_3';
    private const TEST_STREAM_CHANNEL_NAME = 'test_stream_channel_init';

    public function test_sending_fails_when_auto_initialization_disabled_and_channel_not_setup(): void
    {
        $this->cleanUpRabbitMQ();

        $ecotone = $this->bootstrapEcotone(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(false)
        );

        // Try to receive message - should fail because queue doesn't exist and auto-init is disabled
        $this->expectException(Exception::class);
        $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME)->receive();
    }

    public function test_sending_succeeds_when_auto_initialization_enabled_and_channel_auto_declare_disabled(): void
    {
        $this->cleanUpRabbitMQ();

        $ecotone = $this->bootstrapEcotone(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(true),
            autoDeclare: false,
        );

        // Try to receive message - should fail because queue doesn't exist and auto-init is disabled
        $this->expectException(Exception::class);
        $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME)->receive();
    }

    public function test_manual_channel_initialization_via_console_command_then_send_succeeds(): void
    {
        $this->cleanUpRabbitMQ();

        $ecotone = $this->bootstrapEcotone(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(false)
        );

        // Initialize via console command
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);
        $result = $runner->execute('ecotone:migration:channel:setup', ['initialize' => true]);

        // Verify the command output shows initialization
        self::assertNotNull($result);
        $rows = $result->getRows();
        self::assertCount(1, $rows);
        self::assertEquals([self::TEST_CHANNEL_NAME, 'Initialized'], $rows[0]);

        // Now sending should work
        $ecotone->sendDirectToChannel(self::TEST_CHANNEL_NAME, 'test message');

        // Consume and verify
        $message = $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME)->receive();
        self::assertNotNull($message);
        self::assertEquals('test message', $message->getPayload());
    }

    public function test_setup_then_delete_then_send_fails(): void
    {
        $this->cleanUpRabbitMQ();

        $ecotone = $this->bootstrapEcotone(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(false)
        );

        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        // First, initialize the channel
        $runner->execute('ecotone:migration:channel:setup', ['initialize' => true]);

        // Verify we can send and receive
        $ecotone->sendDirectToChannel(self::TEST_CHANNEL_NAME, 'test message 1');
        $message = $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME)->receive();
        self::assertNotNull($message);
        self::assertEquals('test message 1', $message->getPayload());

        // Now delete the channel
        $result = $runner->execute('ecotone:migration:channel:delete', ['force' => true]);
        self::assertNotNull($result);
        $rows = $result->getRows();
        self::assertCount(1, $rows);
        self::assertEquals([self::TEST_CHANNEL_NAME, 'Deleted'], $rows[0]);

        // Try to receive message - should fail because queue was deleted
        $this->expectException(Exception::class);
        $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME)->receive();
    }

    public function test_channel_status_command_shows_initialization_state(): void
    {
        $this->cleanUpRabbitMQ();

        $ecotone = $this->bootstrapEcotone(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(false)
        );

        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        // Check status before initialization
        $result = $runner->execute('ecotone:migration:channel:setup', []);
        self::assertNotNull($result);
        $rows = $result->getRows();
        self::assertCount(1, $rows);
        self::assertEquals([self::TEST_CHANNEL_NAME, 'No'], $rows[0]);

        // Initialize
        $runner->execute('ecotone:migration:channel:setup', ['initialize' => true]);

        // Check status after initialization
        $result = $runner->execute('ecotone:migration:channel:setup', []);
        self::assertNotNull($result);
        $rows = $result->getRows();
        self::assertCount(1, $rows);
        self::assertEquals([self::TEST_CHANNEL_NAME, 'Yes'], $rows[0]);
    }

    public function test_initialize_multiple_channels_at_once(): void
    {
        $this->cleanUpRabbitMQ();

        $ecotone = $this->bootstrapEcotoneWithMultipleChannels(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(false)
        );

        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        // Initialize multiple channels at once
        $result = $runner->execute('ecotone:migration:channel:setup', [
            'channels' => [self::TEST_CHANNEL_NAME_2, self::TEST_CHANNEL_NAME_3],
            'initialize' => true,
        ]);

        self::assertNotNull($result);
        $rows = $result->getRows();
        self::assertCount(2, $rows);
        self::assertEquals([self::TEST_CHANNEL_NAME_2, 'Initialized'], $rows[0]);
        self::assertEquals([self::TEST_CHANNEL_NAME_3, 'Initialized'], $rows[1]);

        // Verify we can send and receive on both channels
        $ecotone->sendDirectToChannel(self::TEST_CHANNEL_NAME_2, 'message 2');
        $ecotone->sendDirectToChannel(self::TEST_CHANNEL_NAME_3, 'message 3');

        $message2 = $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME_2)->receive();
        $message3 = $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME_3)->receive();

        self::assertNotNull($message2);
        self::assertNotNull($message3);
        self::assertEquals('message 2', $message2->getPayload());
        self::assertEquals('message 3', $message3->getPayload());
    }

    public function test_delete_multiple_channels_at_once(): void
    {
        $this->cleanUpRabbitMQ();

        $ecotone = $this->bootstrapEcotoneWithMultipleChannels(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(false)
        );

        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        // Initialize all channels first
        $runner->execute('ecotone:migration:channel:setup', ['initialize' => true]);

        // Delete multiple channels at once
        $result = $runner->execute('ecotone:migration:channel:delete', [
            'channels' => [self::TEST_CHANNEL_NAME_2, self::TEST_CHANNEL_NAME_3],
            'force' => true,
        ]);

        self::assertNotNull($result);
        $rows = $result->getRows();
        self::assertCount(2, $rows);
        self::assertEquals([self::TEST_CHANNEL_NAME_2, 'Deleted'], $rows[0]);
        self::assertEquals([self::TEST_CHANNEL_NAME_3, 'Deleted'], $rows[1]);

        // Verify channels are deleted - receiving should fail
        $this->expectException(Exception::class);
        $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME_2)->receive();
    }

    public function test_stream_channel_initialization(): void
    {
        if (getenv('AMQP_IMPLEMENTATION') !== 'lib') {
            $this->markTestSkipped('Stream tests require AMQP lib');
        }

        $this->cleanUpRabbitMQ();

        $ecotone = $this->bootstrapEcotoneWithStreamChannel(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(false)
        );

        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        // Initialize stream channel
        $result = $runner->execute('ecotone:migration:channel:setup', [
            'channels' => [self::TEST_STREAM_CHANNEL_NAME],
            'initialize' => true,
        ]);

        self::assertNotNull($result);
        $rows = $result->getRows();
        self::assertCount(1, $rows);
        self::assertEquals([self::TEST_STREAM_CHANNEL_NAME, 'Initialized'], $rows[0]);

        // Verify we can send and receive on stream channel
        $ecotone->sendDirectToChannel(self::TEST_STREAM_CHANNEL_NAME, 'stream message');

        $message = $ecotone->getMessageChannel(self::TEST_STREAM_CHANNEL_NAME)->receive();
        self::assertNotNull($message);
        self::assertEquals('stream message', $message->getPayload());

        // Delete stream channel
        $result = $runner->execute('ecotone:migration:channel:delete', [
            'channels' => [self::TEST_STREAM_CHANNEL_NAME],
            'force' => true,
        ]);

        self::assertNotNull($result);
        $rows = $result->getRows();
        self::assertCount(1, $rows);
        self::assertEquals([self::TEST_STREAM_CHANNEL_NAME, 'Deleted'], $rows[0]);
    }

    private function bootstrapEcotone(ChannelInitializationConfiguration $config, bool $autoDeclare = true): \Ecotone\Lite\Test\FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: $this->getConnectionFactoryReferences(),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(\Ecotone\Messaging\Config\ModulePackageList::allPackagesExcept([
                    \Ecotone\Messaging\Config\ModulePackageList::CORE_PACKAGE,
                    \Ecotone\Messaging\Config\ModulePackageList::AMQP_PACKAGE,
                ]))
                ->withExtensionObjects([
                    $config,
                    AmqpBackedMessageChannelBuilder::create(self::TEST_CHANNEL_NAME)
                        ->withAutoDeclare($autoDeclare),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    private function bootstrapEcotoneWithMultipleChannels(ChannelInitializationConfiguration $config): \Ecotone\Lite\Test\FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: $this->getConnectionFactoryReferences(),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(\Ecotone\Messaging\Config\ModulePackageList::allPackagesExcept([
                    \Ecotone\Messaging\Config\ModulePackageList::CORE_PACKAGE,
                    \Ecotone\Messaging\Config\ModulePackageList::AMQP_PACKAGE,
                ]))
                ->withExtensionObjects([
                    $config,
                    AmqpBackedMessageChannelBuilder::create(self::TEST_CHANNEL_NAME_2)->withAutoDeclare(false),
                    AmqpBackedMessageChannelBuilder::create(self::TEST_CHANNEL_NAME_3)->withAutoDeclare(false),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    private function bootstrapEcotoneWithStreamChannel(ChannelInitializationConfiguration $config): \Ecotone\Lite\Test\FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: $this->getConnectionFactoryReferences(),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(\Ecotone\Messaging\Config\ModulePackageList::allPackagesExcept([
                    \Ecotone\Messaging\Config\ModulePackageList::CORE_PACKAGE,
                    \Ecotone\Messaging\Config\ModulePackageList::AMQP_PACKAGE,
                    \Ecotone\Messaging\Config\ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    $config,
                    AmqpQueue::createStreamQueue(self::TEST_STREAM_CHANNEL_NAME),
                    AmqpStreamChannelBuilder::create(
                        self::TEST_STREAM_CHANNEL_NAME,
                        'first',
                        AmqpLibConnection::class,
                        self::TEST_STREAM_CHANNEL_NAME
                    ),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    private function cleanUpRabbitMQ(): void
    {
        try {
            $context = $this->getCachedConnectionFactory()->createContext();

            // Clean up regular channels
            foreach ([self::TEST_CHANNEL_NAME, self::TEST_CHANNEL_NAME_2, self::TEST_CHANNEL_NAME_3, self::TEST_STREAM_CHANNEL_NAME] as $channelName) {
                try {
                    $queue = $context->createQueue($channelName);
                    $context->deleteQueue($queue);
                } catch (Exception $e) {
                    // Queue might not exist, that's fine
                }
            }
        } catch (Exception $e) {
            // Connection might fail, that's fine
        }
    }
}
