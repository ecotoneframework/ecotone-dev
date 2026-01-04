<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\Manager\ChannelInitializationConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Test\Ecotone\Sqs\ConnectionTestCase;

/**
 * Tests for SQS channel initialization with manual setup.
 *
 * licence Apache-2.0
 */
final class SqsChannelInitializationTest extends ConnectionTestCase
{

    private const TEST_CHANNEL_NAME = 'test_channel_init';

    public function test_sending_fails_when_auto_initialization_disabled_and_channel_not_setup(): void
    {
        $this->cleanUpSqs();
        
        $ecotone = $this->bootstrapEcotone(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(false)
        );

        // Try to receive message - should fail because queue doesn't exist and auto-init is disabled
        $this->expectException(\Exception::class);
        $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME)->receive();
    }

    public function test_sending_fails_when_auto_initialization_disabled_and_channel_auto_declare_disabled(): void
    {
        $this->cleanUpSqs();

        $ecotone = $this->bootstrapEcotone(
            ChannelInitializationConfiguration::createWithDefaults()
                ->withAutomaticChannelInitialization(true),
            autoDeclare: false,
        );

        // Try to receive message - should fail because queue doesn't exist and auto-init is disabled
        $this->expectException(\Exception::class);
        $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME)->receive();
    }

    public function test_manual_channel_initialization_via_console_command_then_send_succeeds(): void
    {
        $this->cleanUpSqs();
        
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
        $this->cleanUpSqs();
        
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
        $this->expectException(\Exception::class);
        $ecotone->getMessageChannel(self::TEST_CHANNEL_NAME)->receive();
    }

    public function test_channel_status_command_shows_initialization_state(): void
    {
        $this->cleanUpSqs();
        
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

    private function bootstrapEcotone(ChannelInitializationConfiguration $config, bool $autoDeclare = true): \Ecotone\Lite\Test\FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                self::getConnection(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(\Ecotone\Messaging\Config\ModulePackageList::allPackagesExcept([
                    \Ecotone\Messaging\Config\ModulePackageList::CORE_PACKAGE,
                    \Ecotone\Messaging\Config\ModulePackageList::SQS_PACKAGE,
                ]))
                ->withExtensionObjects([
                    $config,
                    SqsBackedMessageChannelBuilder::create(self::TEST_CHANNEL_NAME)
                        ->withAutoDeclare($autoDeclare),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    public static function cleanUpSqs(): void
    {
        try {
            /** @var \Enqueue\Sqs\SqsContext $context */
            $context = self::getConnection()->createContext();
            $queue = $context->createQueue(self::TEST_CHANNEL_NAME);
            $context->deleteQueue($queue);
        } catch (\Exception $e) {
            // Queue might not exist, that's fine
        }
    }
}

