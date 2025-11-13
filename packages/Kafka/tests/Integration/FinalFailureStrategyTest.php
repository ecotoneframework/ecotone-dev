<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use Exception;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Nonstandard\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class FinalFailureStrategyTest extends TestCase
{
    public function test_single_message_redelivered_and_processed_correctly()
    {
        $topicName = 'test_single_redelivery_' . Uuid::uuid4()->toString();
        $handler = new SingleMessageHandler();

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [SingleMessageHandler::class],
            [$handler, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'kafka_channel', topicName: $topicName)
                        ->withFinalFailureStrategy(FinalFailureStrategy::RELEASE)
                        ->withReceiveTimeout(3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send a message
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.single', new TestCommand('test_message'));

        // First run - should fail and trigger release (offset reset)
        $ecotoneTestSupport->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify handler was called once (and failed)
        $this->assertEquals(1, $handler->getCallCount());
        $this->assertEquals(['test_message'], $handler->getProcessedMessages());

        // Second run - should succeed
        $ecotoneTestSupport->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify handler was called twice (first failed, second succeeded)
        $this->assertEquals(2, $handler->getCallCount());
        $this->assertEquals(['test_message', 'test_message'], $handler->getProcessedMessages());
    }

    public function test_three_messages_second_fails_and_is_released()
    {
        $topicName = 'test_three_messages_' . Uuid::uuid4()->toString();
        $handler = new ThreeMessageHandler();

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [ThreeMessageHandler::class],
            [$handler, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'kafka_channel', topicName: $topicName)
                        ->withFinalFailureStrategy(FinalFailureStrategy::RELEASE)
                        ->withReceiveTimeout(10000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send three messages
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.three', new TestCommand('message_1'));
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.three', new TestCommand('message_2'));
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.three', new TestCommand('message_3'));

        // Run consumer - should process first message, fail on second, and trigger release
        $ecotoneTestSupport->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 10,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify processing pattern:
        // - message_1: processed once (success)
        // - message_2: processed twice (fail, then success after release)
        // - message_3: processed once (success)
        $expectedMessages = ['message_1', 'message_2', 'message_2', 'message_3'];
        $this->assertEquals($expectedMessages, $handler->getProcessedMessages());
        $this->assertEquals(4, $handler->getCallCount());
    }

    public function test_three_messages_second_fails_and_is_resend()
    {
        $topicName = 'test_three_messages_' . Uuid::uuid4()->toString();
        $handler = new ThreeMessageHandler();

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [ThreeMessageHandler::class],
            [$handler, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'kafka_channel', topicName: $topicName)
                        ->withFinalFailureStrategy(FinalFailureStrategy::RESEND)
                        ->withReceiveTimeout(10000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send three messages
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.three', new TestCommand('message_1'));
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.three', new TestCommand('message_2'));
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.three', new TestCommand('message_3'));

        // Run consumer - should process first message, fail on second, and trigger release
        $ecotoneTestSupport->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 10,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify processing pattern:
        // - message_1: processed once (success)
        // - message_2: processed twice (fail, then success after release)
        // - message_3: processed once (success)
        $expectedMessages = ['message_1', 'message_2', 'message_3', 'message_2'];
        $this->assertEquals($expectedMessages, $handler->getProcessedMessages());
        $this->assertEquals(4, $handler->getCallCount());
    }

    public function test_when_new_consumer_starts_it_can_reprocess_released_message()
    {
        $topicName = 'test_two_applications_' . Uuid::uuid4()->toString();
        $handler1 = new TwoApplicationHandler();

        // First application - fails with execution limit 1
        $ecotoneApp = EcotoneLite::bootstrapFlowTesting(
            [TwoApplicationHandler::class],
            [$handler1, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'kafka_channel', topicName: $topicName)
                        ->withFinalFailureStrategy(FinalFailureStrategy::RELEASE)
                        ->withReceiveTimeout(10000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send a message
        $ecotoneApp->sendCommandWithRoutingKey('execute.two_app', new TestCommand('app_test'));

        // First application run - should fail and reset offset
        $ecotoneApp->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify first handler was called once
        $this->assertEquals(1, $handler1->getCallCount());
        $this->assertEquals(['app_test'], $handler1->getProcessedMessages());

        // Second run - should succeed
        $ecotoneApp->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify second handler was called once
        $this->assertEquals(2, $handler1->getCallCount());
        $this->assertEquals(['app_test', 'app_test'], $handler1->getProcessedMessages());
    }

    public function test_when_new_consumer_starts_it_skips_ignored_message()
    {
        $topicName = 'test_ignore_shared_' . Uuid::uuid4()->toString();
        $sharedGroupId = 'shared_consumer_group_' . Uuid::uuid4()->toString();
        $handler = new IgnoreTestHandler();

        // Single application that will process multiple messages
        $ecotoneApp = EcotoneLite::bootstrapFlowTesting(
            [IgnoreTestHandler::class],
            [$handler, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: 'kafka_channel',
                        topicName: $topicName,
                        messageGroupId: $sharedGroupId
                    )
                        ->withFinalFailureStrategy(FinalFailureStrategy::IGNORE)
                        ->withReceiveTimeout(10000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send two messages
        $ecotoneApp->sendCommandWithRoutingKey('execute.ignore_test', new TestCommand('message_1'));
        $ecotoneApp->sendCommandWithRoutingKey('execute.ignore_test', new TestCommand('message_2'));

        // First run - should process first message (fail and ignore), then process second message (succeed)
        $ecotoneApp->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify behavior:
        // - message_1: processed once and failed (ignored)
        // - message_2: processed once and succeeded
        // Total calls: 2, but only message_2 should be in successful messages
        $this->assertEquals(2, $handler->getCallCount());
        $this->assertEquals(['message_1', 'message_2'], $handler->getAllProcessedMessages());
        $this->assertEquals(['message_2'], $handler->getSuccessfulMessages()); // Only message_2 succeeded

        $ecotoneApp->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify behavior is unchanged
        $this->assertEquals(2, $handler->getCallCount());
        $this->assertEquals(['message_1', 'message_2'], $handler->getAllProcessedMessages());
        $this->assertEquals(['message_2'], $handler->getSuccessfulMessages());
    }

    public function test_different_message_groups_will_handle_ignored_message_twice()
    {
        $topicName = 'test_ignore_shared_' . Uuid::uuid4()->toString();
        $handler1 = new IgnoreTestHandler();
        $handler2 = new IgnoreTestHandler();

        // Single application that will process multiple messages
        $ecotoneApp = EcotoneLite::bootstrapFlowTesting(
            [IgnoreTestHandler::class],
            [$handler1, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: 'kafka_channel',
                        topicName: $topicName,
                        messageGroupId: Uuid::uuid4()->toString()
                    )
                        ->withFinalFailureStrategy(FinalFailureStrategy::IGNORE)
                        ->withReceiveTimeout(10000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send two messages
        $ecotoneApp->sendCommandWithRoutingKey('execute.ignore_test', new TestCommand('message_1'));
        $ecotoneApp->sendCommandWithRoutingKey('execute.ignore_test', new TestCommand('message_2'));

        // First run - should process first message (fail and ignore), then process second message (succeed)
        $ecotoneApp->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify behavior:
        // - message_1: processed once and failed (ignored)
        // - message_2: processed once and succeeded
        // Total calls: 2, but only message_2 should be in successful messages
        $this->assertEquals(2, $handler1->getCallCount());
        $this->assertEquals(['message_1', 'message_2'], $handler1->getAllProcessedMessages());
        $this->assertEquals(['message_2'], $handler1->getSuccessfulMessages()); // Only message_2 succeeded

        // Single application that will process multiple messages
        $ecotoneApp = EcotoneLite::bootstrapFlowTesting(
            [IgnoreTestHandler::class],
            [$handler2, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: 'kafka_channel',
                        topicName: $topicName,
                        messageGroupId: Uuid::uuid4()->toString()
                    )
                        ->withFinalFailureStrategy(FinalFailureStrategy::IGNORE)
                        ->withReceiveTimeout(10000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneApp->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false
        ));

        // Verify behavior is unchanged
        $this->assertEquals(2, $handler2->getCallCount());
        $this->assertEquals(['message_1', 'message_2'], $handler2->getAllProcessedMessages());
        $this->assertEquals(['message_2'], $handler2->getSuccessfulMessages());
    }
}

class TestCommand
{
    public function __construct(public readonly string $payload)
    {
    }
}

class SingleMessageHandler
{
    private int $callCount = 0;
    private array $processedMessages = [];
    private bool $shouldFail = true;

    #[Asynchronous('kafka_channel')]
    #[CommandHandler('execute.single', 'single_endpoint')]
    public function handle(TestCommand $command): void
    {
        $this->callCount++;
        $this->processedMessages[] = $command->payload;

        // Fail on first call, succeed on second
        if ($this->shouldFail) {
            $this->shouldFail = false;
            throw new Exception('Simulated failure for redelivery test');
        }
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}

class ThreeMessageHandler
{
    private int $callCount = 0;
    private array $processedMessages = [];
    private bool $shouldFailOnSecond = true;

    #[Asynchronous('kafka_channel')]
    #[CommandHandler('execute.three', 'three_endpoint')]
    public function handle(TestCommand $command): void
    {
        $this->callCount++;
        $this->processedMessages[] = $command->payload;

        // Fail only on the first occurrence of message_2
        if ($command->payload === 'message_2' && $this->shouldFailOnSecond) {
            $this->shouldFailOnSecond = false;
            throw new Exception('Simulated failure on second message');
        }
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}

class TwoApplicationHandler
{
    private int $callCount = 0;
    private array $processedMessages = [];
    private static bool $shouldFail = true;

    #[Asynchronous('kafka_channel')]
    #[CommandHandler('execute.two_app', 'two_app_endpoint')]
    public function handle(TestCommand $command): void
    {
        $this->callCount++;
        $this->processedMessages[] = $command->payload;

        // First application fails, second succeeds
        if (self::$shouldFail) {
            self::$shouldFail = false;
            throw new Exception('Simulated failure for first application');
        }
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}

class IgnoreTestHandler
{
    private int $callCount = 0;
    private array $allProcessedMessages = [];
    private array $successfulMessages = [];

    #[Asynchronous('kafka_channel')]
    #[CommandHandler('execute.ignore_test', 'ignore_test_endpoint')]
    public function handle(TestCommand $command): void
    {
        $this->callCount++;
        $this->allProcessedMessages[] = $command->payload;

        // Fail only on message_1, succeed on others
        if ($command->payload === 'message_1') {
            throw new Exception('Simulated failure for message_1 (should be ignored)');
        }

        // If we reach here, the message was processed successfully
        $this->successfulMessages[] = $command->payload;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getAllProcessedMessages(): array
    {
        return $this->allProcessedMessages;
    }

    public function getSuccessfulMessages(): array
    {
        return $this->successfulMessages;
    }
}
