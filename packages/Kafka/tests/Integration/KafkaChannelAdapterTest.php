<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Api\KafkaHeader;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Kafka\Outbound\MessagePublishingException;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;
use Test\Ecotone\Kafka\Fixture\ChannelAdapter\ExampleKafkaConsumer;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerWithDelayedRetryExample;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerWithFailStrategyExample;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerWithInstantRetryAndErrorChannelExample;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerWithInstantRetryExample;

/**
 * licence Enterprise
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class KafkaChannelAdapterTest extends TestCase
{
    public function test_sending_and_receiving_from_kafka_topic(): void
    {
        $ecotoneLite = $this->bootstrapFlowTesting();

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        $kafkaPublisher->sendWithMetadata('exampleData', 'application/text', ['key' => 'value']);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            maxExecutionTimeInMilliseconds: 30000
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');

        self::assertCount(1, $messages);
        self::assertEquals('exampleData', $messages[0]['payload']);
        self::assertEquals('value', $messages[0]['metadata']['key']);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup());

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');
        self::assertCount(1, $messages);
    }

    /**
     * @dataProvider providePartitionKeySet
     */
    public function test_sending_with_partition_keys(array $metadata, string $expectedKey)
    {
        $ecotoneLite = $this->bootstrapFlowTesting();

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        $kafkaPublisher->sendWithMetadata('exampleData', 'application/text', $metadata);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            maxExecutionTimeInMilliseconds: 30000
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');

        self::assertCount(1, $messages);
        self::assertEquals($expectedKey, $messages[0]['metadata'][KafkaHeader::KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME]);
    }

    public static function providePartitionKeySet(): iterable
    {
        $messageId = Uuid::uuid4()->toString();
        $aggregateId = Uuid::uuid4()->toString();
        $eventAggregateId = Uuid::uuid4()->toString();
        $customKey = Uuid::uuid4()->toString();
        $intKey = 2;

        yield 'with no partition key, message id is used' => [
            'metadata' => [MessageHeaders::MESSAGE_ID => $messageId],
            'expectedKey' => $messageId,
        ];
        yield 'with event aggregate id as partition key' => [
            'metadata' => [MessageHeaders::MESSAGE_ID => $messageId, MessageHeaders::EVENT_AGGREGATE_ID => $eventAggregateId],
            'expectedKey' => $eventAggregateId,
        ];
        yield 'with target aggregate id header' => [
            'metadata' => [MessageHeaders::MESSAGE_ID => $messageId, MessageHeaders::EVENT_AGGREGATE_ID => $eventAggregateId, AggregateMessage::AGGREGATE_ID => ['orderId' => $aggregateId]],
            'expectedKey' => $aggregateId,
        ];
        yield 'with custom partition key' => [
            'metadata' => [MessageHeaders::MESSAGE_ID => $messageId, MessageHeaders::EVENT_AGGREGATE_ID => $eventAggregateId, AggregateMessage::AGGREGATE_ID => ['orderId' => $aggregateId], KafkaHeader::KAFKA_TARGET_PARTITION_KEY_HEADER_NAME => $customKey],
            'expectedKey' => $customKey,
        ];
        yield 'with partition being int' => [
            'metadata' => [MessageHeaders::MESSAGE_ID => $messageId, KafkaHeader::KAFKA_TARGET_PARTITION_KEY_HEADER_NAME => $intKey],
            'expectedKey' => (string)$intKey,
        ];
    }

    public function test_throwing_exception_on_failure_during_sending(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([
                'wrongKafkaDsn',
            ]), 'logger' => new EchoLogger()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults(Uuid::uuid4()->toString()),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectException(MessagePublishingException::class);

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        $kafkaPublisher->sendWithMetadata('exampleData', 'application/text', ['key' => 'value']);
    }

    public function bootstrapFlowTesting(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [ExampleKafkaConsumer::class],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(), new ExampleKafkaConsumer(),
                //                'logger' => new EchoLogger()
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults($topicName = Uuid::uuid4()->toString()),
                    TopicConfiguration::createWithReferenceName('exampleTopic', $topicName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_defining_custom_failure_strategy(): void
    {
        $endpointId = 'kafka_consumer_attribute';
        $topicName = 'test_topic_failure_' . Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithFailStrategyExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithFailStrategyExample(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults($topicName)
                        ->withHeaderMapper('*'),
                    TopicConfiguration::createWithReferenceName('testTopicFailure', $topicName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $this->assertEquals([$payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: true));
        $this->assertEquals([$payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }

    public function test_defining_instant_retries(): void
    {
        $endpointId = 'kafka_consumer_attribute';
        $topicName = 'test_topic_retry_' . Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithInstantRetryExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithInstantRetryExample(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults($topicName)
                        ->withHeaderMapper('*'),
                    TopicConfiguration::createWithReferenceName('testTopicRetry', $topicName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads');
        $this->assertCount(2, $messages);
        $this->assertEquals($payload, $messages[0]);
        $this->assertEquals($payload, $messages[1]);

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: true));
        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads');
        $this->assertCount(2, $messages);
    }

    public function test_defining_error_channel(): void
    {
        $endpointId = 'kafka_consumer_attribute';
        $topicName = 'test_topic_error_' . Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithInstantRetryAndErrorChannelExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithInstantRetryAndErrorChannelExample(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults($topicName)
                        ->withHeaderMapper('*'),
                    TopicConfiguration::createWithReferenceName('testTopicError', $topicName),
                    SimpleMessageChannelBuilder::createQueueChannel('customErrorChannel'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads');
        $this->assertCount(2, $messages);
        $this->assertEquals($payload, $messages[0]);
        $this->assertEquals($payload, $messages[1]);

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: true));
        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads');
        $this->assertCount(2, $messages);

        $this->assertNotNull($ecotoneLite->getMessageChannel('customErrorChannel')->receive());
    }

    public function test_kafka_consumer_with_delayed_retry(): void
    {
        $endpointId = 'kafka_consumer_delayed_retry';
        $topicName = 'test_topic_delayed_retry_' . Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithDelayedRetryExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithDelayedRetryExample(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults($topicName)
                        ->withHeaderMapper('*'),
                    TopicConfiguration::createWithReferenceName('testTopicDelayedRetry', $topicName),
                    SimpleMessageChannelBuilder::createQueueChannel('delayedRetryChannel'),
                    // Configure delayed retry with exponential backoff
                    ErrorHandlerConfiguration::createWithDeadLetterChannel(
                        errorChannelName: 'delayedRetryChannel',
                        delayedRetryTemplate: RetryTemplateBuilder::exponentialBackoff(
                            initialDelay: 100,  // 100ms initial delay for testing
                            multiplier: 2       // Each retry is 2x longer (100ms, 200ms, 400ms...)
                        )->maxRetryAttempts(2), // Maximum 2 delayed retry attempts
                        deadLetterChannel: 'dbal_dead_letter'
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        // First run: Instant retry (2 attempts: original + 1 retry)
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $attempts = $ecotoneLite->sendQueryWithRouting('consumer.getDelayedRetryAttempts');
        $this->assertCount(2, $attempts, 'Should have 2 attempts from instant retry (original + 1 instant retry)');
        $this->assertEquals($payload, $attempts[0]['payload']);
        $this->assertEquals(1, $attempts[0]['attempt']);
        $this->assertEquals(2, $attempts[1]['attempt']);

        // Verify message was sent to error channel for delayed retry
        $errorMessage = $ecotoneLite->getMessageChannel('delayedRetryChannel')->receive();
        $this->assertNotNull($errorMessage, 'Message should be in delayed retry channel after instant retries exhausted');

        // Verify failure count after instant retries
        $failureCount = $ecotoneLite->sendQueryWithRouting('consumer.getFailureCount');
        $this->assertEquals(2, $failureCount, 'Should have failed 2 times during instant retry');

        // The delayed retry mechanism works by:
        // 1. Instant retry happens immediately (2 attempts total)
        // 2. After instant retries fail, message goes to error channel
        // 3. Error handler adds delay header and sends back to original channel
        // 4. Consumer would pick it up again after delay (in real scenario)
        // 5. This continues until max delayed retries reached or success
        // 6. If all retries fail, message goes to dead letter channel or is resent to Kafka (based on finalFailureStrategy)
    }
}
