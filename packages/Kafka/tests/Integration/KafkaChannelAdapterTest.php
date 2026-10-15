<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\Kafka\KafkaHeader;
use Ecotone\Api\Kafka\KafkaPublisherConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Kafka\Outbound\MessagePublishingException;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;
use Test\Ecotone\Kafka\Fixture\ChannelAdapter\ExampleKafkaConsumer;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerFailingExample;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerWithDelayedRetryExample;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerWithFailStrategyExample;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerWithInstantRetryAndErrorChannelExample;
use Test\Ecotone\Kafka\Fixture\KafkaConsumer\KafkaConsumerWithInstantRetryExample;
use Test\Ecotone\Kafka\Fixture\MediaTypeConverter\JsonEncodingConverter;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
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
            handledMessageLimit: 1,
            executionTimeLimitInMilliseconds: 30000
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');

        self::assertCount(1, $messages);
        self::assertEquals('exampleData', $messages[0]['payload']);
        self::assertEquals('value', $messages[0]['metadata']['key']);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup());

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');
        self::assertCount(1, $messages);
    }

    #[DataProvider('providePartitionKeySet')]
    public function test_sending_with_partition_keys(array $metadata, string $expectedKey)
    {
        $ecotoneLite = $this->bootstrapFlowTesting();

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        $kafkaPublisher->sendWithMetadata('exampleData', 'application/text', $metadata);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            executionTimeLimitInMilliseconds: 30000
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');

        self::assertCount(1, $messages);
        self::assertEquals($expectedKey, $messages[0]['metadata'][KafkaHeader::KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME]);
    }

    public static function providePartitionKeySet(): iterable
    {
        $messageId = Uuid::v7()->toRfc4122();
        $aggregateId = Uuid::v7()->toRfc4122();
        $eventAggregateId = Uuid::v7()->toRfc4122();
        $customKey = Uuid::v7()->toRfc4122();
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
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults(Uuid::v7()->toRfc4122()),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
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
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName = Uuid::v7()->toRfc4122()),
                TopicConfiguration::createWithReferenceName('exampleTopic', $topicName),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_defining_custom_failure_strategy(): void
    {
        $endpointId = 'kafka_consumer_attribute';
        $topicName = 'test_topic_failure_' . Uuid::v7()->toRfc4122();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithFailStrategyExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithFailStrategyExample(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName)->withHeaderMapper('*'),
                TopicConfiguration::createWithReferenceName('testTopicFailure', $topicName),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            environment: 'prod',
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $this->assertEquals([$payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: true));
        $this->assertEquals([$payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }

    public function test_defining_instant_retries(): void
    {
        $endpointId = 'kafka_consumer_attribute';
        $topicName = 'test_topic_retry_' . Uuid::v7()->toRfc4122();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithInstantRetryExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithInstantRetryExample(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName)->withHeaderMapper('*'),
                TopicConfiguration::createWithReferenceName('testTopicRetry', $topicName),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            environment: 'prod',
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads');
        $this->assertCount(2, $messages);
        $this->assertEquals($payload, $messages[0]);
        $this->assertEquals($payload, $messages[1]);

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: true));
        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads');
        $this->assertCount(2, $messages);
    }

    public function test_defining_error_channel(): void
    {
        $endpointId = 'kafka_consumer_attribute';
        $topicName = 'test_topic_error_' . Uuid::v7()->toRfc4122();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithInstantRetryAndErrorChannelExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithInstantRetryAndErrorChannelExample(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName)->withHeaderMapper('*'),
                TopicConfiguration::createWithReferenceName('testTopicError', $topicName),
                SimpleMessageChannelBuilder::createQueueChannel('customErrorChannel'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            environment: 'prod',
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads');
        $this->assertCount(2, $messages);
        $this->assertEquals($payload, $messages[0]);
        $this->assertEquals($payload, $messages[1]);

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: true));
        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads');
        $this->assertCount(2, $messages);

        $this->assertNotNull($ecotoneLite->getMessageChannel('customErrorChannel')->receive());
    }

    public function test_default_custom_error_channel_on_consumer(): void
    {
        $topicName = Uuid::v7()->toRfc4122();
        $publisherReferenceName =  'kafka_publisher';
        $consumerReferenceName = 'kafka_consumer_attribute';

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerFailingExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerFailingExample(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName, $publisherReferenceName),
                TopicConfiguration::createWithReferenceName('testTopicFailure', $topicName),
                KafkaConsumerConfiguration::createWithDefaults($consumerReferenceName),
                SimpleMessageChannelBuilder::createQueueChannel('customErrorChannel'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('customErrorChannel'),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var KafkaAdmin $kafkaAdmin */
        $kafkaAdmin = $ecotoneLite->getServiceFromContainer(KafkaAdmin::class);
        $kafkaAdmin->getTopicForProducer($publisherReferenceName . '.handler')
            ->produce(RD_KAFKA_PARTITION_UA, 0, Uuid::v7()->toRfc4122());
        $kafkaAdmin->getProducer($publisherReferenceName. '.handler')->flush(8000);

        $ecotoneLite->run($consumerReferenceName, ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            executionTimeLimitInMilliseconds: 30000
        )->withStopOnError(false));

        $this->assertNotNull($ecotoneLite->getMessageChannel('customErrorChannel')->receive());
    }

    public function test_convert_and_send(): void
    {
        $topicName = Uuid::v7()->toRfc4122();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleKafkaConsumer::class, JsonEncodingConverter::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new ExampleKafkaConsumer(),
                new JsonEncodingConverter(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName, MessagePublisher::class, KafkaBrokerConfiguration::class, MediaType::APPLICATION_JSON),
                TopicConfiguration::createWithReferenceName('exampleTopic', $topicName),
                KafkaConsumerConfiguration::createWithDefaults('exampleConsumer'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        $stdClass = new stdClass();
        $stdClass->value = 1;
        $kafkaPublisher->convertAndSend($stdClass);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            executionTimeLimitInMilliseconds: 30000
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');

        self::assertCount(1, $messages);
        self::assertEquals('{"value":1}', $messages[0]['payload']);
    }

    public function test_kafka_consumer_with_delayed_retry(): void
    {
        $endpointId = 'kafka_consumer_delayed_retry';
        $topicName = 'test_topic_delayed_retry_' . Uuid::v7()->toRfc4122();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithDelayedRetryExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithDelayedRetryExample(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName)->withHeaderMapper('*'),
                TopicConfiguration::createWithReferenceName('testTopicDelayedRetry', $topicName),
                SimpleMessageChannelBuilder::createQueueChannel('delayedRetryChannel'),
                ErrorHandlerConfiguration::createWithDeadLetterChannel(errorChannelName:'delayedRetryChannel', delayedRetryTemplate:RetryTemplateBuilder::exponentialBackOff(initialDelayInMilliseconds:100, multiplier:2)->maxRetries(2), deadLetterChannel:'dbal_dead_letter'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            environment: 'prod',
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        // First run: Instant retry (2 attempts: original + 1 retry)
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
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

    public function test_sending_and_receiving_with_kafka_consumer_configuration(): void
    {
        $topicName = Uuid::v7()->toRfc4122();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleKafkaConsumer::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new ExampleKafkaConsumer(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName),
                TopicConfiguration::createWithReferenceName('exampleTopic', $topicName),
                KafkaConsumerConfiguration::createWithDefaults('exampleConsumer'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        $kafkaPublisher->sendWithMetadata('exampleData', 'application/text', ['key' => 'value']);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            executionTimeLimitInMilliseconds: 30000
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');

        self::assertCount(1, $messages);
        self::assertEquals('exampleData', $messages[0]['payload']);
    }

    public function test_sending_and_receiving_without_kafka_consumer_configuration(): void
    {
        $topicName = Uuid::v7()->toRfc4122();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleKafkaConsumer::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new ExampleKafkaConsumer(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName),
                TopicConfiguration::createWithReferenceName('exampleTopic', $topicName),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        $kafkaPublisher->sendWithMetadata('exampleData', 'application/text', ['key' => 'value']);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            executionTimeLimitInMilliseconds: 30000
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');

        self::assertCount(1, $messages);
        self::assertEquals('exampleData', $messages[0]['payload']);
    }

    public function test_kafka_consumer_works_without_explicit_configuration(): void
    {
        $topicName = 'test_topic_no_config_' . Uuid::v7()->toRfc4122();

        $consumer = new class () {
            private array $messages = [];

            #[KafkaConsumer('ordersConsumer', 'orders')]
            public function handle(string $payload): void
            {
                $this->messages[] = $payload;
            }

            #[QueryHandler('consumer.getMessages')]
            public function getMessages(): array
            {
                return $this->messages;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$consumer::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                $consumer,
            ],
            extensionObjects: [
                TopicConfiguration::createWithReferenceName('orders', $topicName),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->run('ordersConsumer', ExecutionPollingMetadata::createWithTestingSetup());

        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getMessages');

        self::assertCount(0, $messages);
    }

    public function test_kafka_publisher_works_without_explicit_configuration(): void
    {
        $topicName = 'test_topic_publisher_' . Uuid::v7()->toRfc4122();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $kafkaPublisher->send('test-payload');

        $this->assertTrue(true);
    }
}
