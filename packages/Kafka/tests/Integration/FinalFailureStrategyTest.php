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
            maxExecutionTimeInMilliseconds: 3000,
            failAtError: false
        ));

        // Verify handler was called once (and failed)
        $this->assertEquals(1, $handler->getCallCount());
        $this->assertEquals(['test_message'], $handler->getProcessedMessages());

        // Second run - should succeed
        $ecotoneTestSupport->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 3000,
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
                        ->withReceiveTimeout(3000),
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
            maxExecutionTimeInMilliseconds: 5000,
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
                        ->withReceiveTimeout(3000),
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
            maxExecutionTimeInMilliseconds: 5000,
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
        $handler2 = new TwoApplicationHandler();

        // First application - fails with execution limit 1
        $ecotoneApp1 = EcotoneLite::bootstrapFlowTesting(
            [TwoApplicationHandler::class],
            [$handler1, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
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
        $ecotoneApp1->sendCommandWithRoutingKey('execute.two_app', new TestCommand('app_test'));

        // First application run - should fail and reset offset
        $ecotoneApp1->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 3000,
            failAtError: false
        ));

        // Verify first handler was called once
        $this->assertEquals(1, $handler1->getCallCount());
        $this->assertEquals(['app_test'], $handler1->getProcessedMessages());

        // Second application - should process the redelivered message
        $ecotoneApp2 = EcotoneLite::bootstrapFlowTesting(
            [TwoApplicationHandler::class],
            [$handler2, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'kafka_channel', topicName: $topicName)
                        ->withFinalFailureStrategy(FinalFailureStrategy::RELEASE)
                        ->withReceiveTimeout(3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Second application run - should succeed
        $ecotoneApp2->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 3000,
            failAtError: false
        ));

        // Verify second handler was called once
        $this->assertEquals(1, $handler2->getCallCount());
        $this->assertEquals(['app_test'], $handler2->getProcessedMessages());

        // Verify first handler wasn't called again
        $this->assertEquals(1, $handler1->getCallCount());
    }

    public function test_when_new_consumer_starts_it_skips_ignored_message()
    {
        $topicName = 'test_two_applications_' . Uuid::uuid4()->toString();
        $handler1 = new TwoApplicationHandler();
        $handler2 = new TwoApplicationHandler();

        // First application - fails with execution limit 1
        $ecotoneApp1 = EcotoneLite::bootstrapFlowTesting(
            [TwoApplicationHandler::class],
            [$handler1, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'kafka_channel', topicName: $topicName)
                        ->withFinalFailureStrategy(FinalFailureStrategy::IGNORE)
                        ->withReceiveTimeout(3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send a message
        $ecotoneApp1->sendCommandWithRoutingKey('execute.two_app', new TestCommand('app_test'));

        // First application run - should fail and reset offset
        $ecotoneApp1->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 3000,
            failAtError: false
        ));

        // Verify first handler was called once
        $this->assertEquals(1, $handler1->getCallCount());
        $this->assertEquals(['app_test'], $handler1->getProcessedMessages());

        // Second application - should process the redelivered message
        $ecotoneApp2 = EcotoneLite::bootstrapFlowTesting(
            [TwoApplicationHandler::class],
            [$handler2, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'kafka_channel', topicName: $topicName)
                        ->withFinalFailureStrategy(FinalFailureStrategy::IGNORE)
                        ->withReceiveTimeout(3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Second application run - should succeed
        $ecotoneApp2->run('kafka_channel', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 3000,
            failAtError: false
        ));

        // Verify second handler was called once
        $this->assertEquals(0, $handler2->getCallCount());
        $this->assertEquals([], $handler2->getProcessedMessages());

        // Verify first handler wasn't called again
        $this->assertEquals(1, $handler1->getCallCount());
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


