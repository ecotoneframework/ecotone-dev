<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\MessageConsumer;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Lite\EcotoneLite;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Monorepo\CrossModuleTests\Fixture\Deduplication\KafkaConsumerWithDeduplicationExample;
use Monorepo\CrossModuleTests\Fixture\Deduplication\KafkaConsumerWithDefaultDeduplicationExample;
use Monorepo\CrossModuleTests\Fixture\Deduplication\KafkaConsumerWithExpressionDeduplicationExample;
use Monorepo\CrossModuleTests\Fixture\Deduplication\KafkaConsumerWithPayloadExpressionDeduplicationExample;
use Monorepo\CrossModuleTests\Tests\MessagingTestCase;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Kafka\ConnectionTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class KafkaConsumerDeduplicationTest extends TestCase
{
    protected function setUp(): void
    {
        MessagingTestCase::cleanUpDbal();
        DbalMessagingTestCase::cleanUpDbalTables(DbalMessagingTestCase::prepareConnection()->createContext()->getDbalConnection());
    }

    protected function tearDown(): void
    {
        MessagingTestCase::cleanUpDbal();
        DbalMessagingTestCase::cleanUpDbalTables(DbalMessagingTestCase::prepareConnection()->createContext()->getDbalConnection());
    }

    public function test_deduplicating_with_custom_header_name_kafka_consumer(): void
    {
        $topicName = 'deduplication_topic_' . Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithDeduplicationExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithDeduplicationExample(),
                DbalConnectionFactory::class => DbalMessagingTestCase::prepareConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::KAFKA_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE
                ]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                    TopicConfiguration::createWithReferenceName('deduplication_topic', $topicName),
                    KafkaPublisherConfiguration::createWithDefaults($topicName)
                        ->withHeaderMapper('*'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        /** @var MessagePublisher $messagePublisher */
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send message with custom header
        $customOrderId = 'order-123-kafka-' . Uuid::uuid4()->toString();
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            ['customOrderId' => $customOrderId]
        );
        
        // Run consumer
        $ecotoneLite->run('kafka_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());
        
        // Verify message processed
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('kafka.getProcessedMessages'));
        
        // Send same message with same custom header
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            ['customOrderId' => $customOrderId]
        );
        
        // Run consumer again
        $ecotoneLite->run('kafka_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());
        
        // Verify message NOT processed again (still only one message)
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('kafka.getProcessedMessages'));
        
        // Send message with different custom header
        $messagePublisher->sendWithMetadata(
            'test-payload-2',
            'application/text',
            ['customOrderId' => 'order-456-kafka-' . Uuid::uuid4()->toString()]
        );
        
        // Run consumer
        $ecotoneLite->run('kafka_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());
        
        // Verify new message IS processed
        $this->assertEquals(['test-payload-1', 'test-payload-2'], $ecotoneLite->sendQueryWithRouting('kafka.getProcessedMessages'));
    }

    public function test_deduplicating_with_default_message_id_kafka_consumer(): void
    {
        $topicName = 'default_deduplication_topic_' . Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithDefaultDeduplicationExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithDefaultDeduplicationExample(),
                DbalConnectionFactory::class => DbalMessagingTestCase::prepareConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::KAFKA_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE
                ]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                    TopicConfiguration::createWithReferenceName('default_deduplication_topic', $topicName),
                    KafkaPublisherConfiguration::createWithDefaults($topicName)
                        ->withHeaderMapper('*'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        /** @var MessagePublisher $messagePublisher */
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send message with specific MESSAGE_ID
        $messageId = 'msg-123-kafka-' . Uuid::uuid4()->toString();
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            [MessageHeaders::MESSAGE_ID => $messageId]
        );
        
        // Run consumer
        $ecotoneLite->run('kafka_default_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());
        
        // Verify message processed
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('kafka.getDefaultProcessedMessages'));
        
        // Send same message with same MESSAGE_ID
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            [MessageHeaders::MESSAGE_ID => $messageId]
        );
        
        // Run consumer again
        $ecotoneLite->run('kafka_default_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());
        
        // Verify message NOT processed again (still only one message)
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('kafka.getDefaultProcessedMessages'));
        
        // Send message with different MESSAGE_ID
        $messagePublisher->sendWithMetadata(
            'test-payload-2',
            'application/text',
            [MessageHeaders::MESSAGE_ID => 'msg-456-kafka-' . Uuid::uuid4()->toString()]
        );
        
        // Run consumer
        $ecotoneLite->run('kafka_default_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());
        
        // Verify new message IS processed
        $this->assertEquals(['test-payload-1', 'test-payload-2'], $ecotoneLite->sendQueryWithRouting('kafka.getDefaultProcessedMessages'));
    }

    public function test_deduplication_works_independently_across_different_consumers(): void
    {
        $topicName = 'deduplication_topic_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithDeduplicationExample::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                new KafkaConsumerWithDeduplicationExample(),
                DbalConnectionFactory::class => DbalMessagingTestCase::prepareConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::KAFKA_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE
                ]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                    TopicConfiguration::createWithReferenceName('deduplication_topic', $topicName),
                    KafkaPublisherConfiguration::createWithDefaults($topicName)
                        ->withHeaderMapper('*'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        /** @var MessagePublisher $messagePublisher */
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send message with custom header value 'order-123'
        $customOrderId1 = 'order-123-kafka-independent-' . Uuid::uuid4()->toString();
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            ['customOrderId' => $customOrderId1]
        );

        // Send message with different custom header value 'order-456'
        $customOrderId2 = 'order-456-kafka-independent-' . Uuid::uuid4()->toString();
        $messagePublisher->sendWithMetadata(
            'test-payload-2',
            'application/text',
            ['customOrderId' => $customOrderId2]
        );

        // Run consumer to process first message
        $ecotoneLite->run('kafka_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup()->withHandledMessageLimit(1));

        // Verify first message processed
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('kafka.getProcessedMessages'));

        // Run consumer to process second message
        $ecotoneLite->run('kafka_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup()->withHandledMessageLimit(1));

        // Verify both messages processed (different custom header values)
        $this->assertEquals(['test-payload-1', 'test-payload-2'], $ecotoneLite->sendQueryWithRouting('kafka.getProcessedMessages'));

        // Send duplicate messages with same custom header values
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            ['customOrderId' => $customOrderId1]
        );

        $messagePublisher->sendWithMetadata(
            'test-payload-2',
            'application/text',
            ['customOrderId' => $customOrderId2]
        );

        // Run consumer again
        $ecotoneLite->run('kafka_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup()->withHandledMessageLimit(2));

        // Verify no duplicate processing occurred (still only 2 messages)
        $this->assertEquals(['test-payload-1', 'test-payload-2'], $ecotoneLite->sendQueryWithRouting('kafka.getProcessedMessages'));
    }
}
