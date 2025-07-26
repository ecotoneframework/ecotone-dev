<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\MessageConsumer;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Lite\EcotoneLite;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use Monorepo\CrossModuleTests\Fixture\Deduplication\RabbitConsumerWithCustomDeduplicationExample;
use Monorepo\CrossModuleTests\Fixture\Deduplication\RabbitConsumerWithDefaultDeduplicationExample2;
use Monorepo\CrossModuleTests\Fixture\Deduplication\RabbitConsumerWithExpressionDeduplicationExample;
use Monorepo\CrossModuleTests\Fixture\Deduplication\RabbitConsumerWithIndependentDeduplicationExample;
use Monorepo\CrossModuleTests\Tests\MessagingTestCase;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class RabbitConsumerDeduplicationTest extends TestCase
{
    protected function setUp(): void
    {
        MessagingTestCase::cleanRabbitMQ();
        DbalMessagingTestCase::cleanUpDbalTables(DbalMessagingTestCase::prepareConnection()->createContext()->getDbalConnection());
    }

    protected function tearDown(): void
    {
        MessagingTestCase::cleanRabbitMQ();
        DbalMessagingTestCase::cleanUpDbalTables(DbalMessagingTestCase::prepareConnection()->createContext()->getDbalConnection());
    }

    public function test_deduplicating_with_custom_header_name_rabbit_consumer(): void
    {
        $queueName = 'deduplication_queue_custom';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RabbitConsumerWithCustomDeduplicationExample::class],
            [
                AmqpConnectionFactory::class => AmqpMessagingTestCase::getRabbitConnectionFactory(),
                new RabbitConsumerWithCustomDeduplicationExample(),
                DbalConnectionFactory::class => DbalMessagingTestCase::prepareConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::AMQP_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE
                ]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        /** @var MessagePublisher $messagePublisher */
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send message with custom header
        $customOrderId = 'order-123-custom-' . Uuid::uuid4()->toString();
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            ['customOrderId' => $customOrderId]
        );
        
        // Run consumer
        $ecotoneLite->run('rabbit_custom_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());

        // Verify message processed
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('rabbit.getCustomProcessedMessages'));

        // Send same message with same custom header
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            ['customOrderId' => $customOrderId]
        );

        // Run consumer again
        $ecotoneLite->run('rabbit_custom_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());

        // Verify message NOT processed again (still only one message)
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('rabbit.getCustomProcessedMessages'));

        // Send message with different custom header
        $messagePublisher->sendWithMetadata(
            'test-payload-2',
            'application/text',
            ['customOrderId' => 'order-456-custom-' . Uuid::uuid4()->toString()]
        );

        // Run consumer
        $ecotoneLite->run('rabbit_custom_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup());

        // Verify new message IS processed
        $this->assertEquals(['test-payload-1', 'test-payload-2'], $ecotoneLite->sendQueryWithRouting('rabbit.getCustomProcessedMessages'));
    }

    public function test_deduplicating_with_default_message_id_rabbit_consumer(): void
    {
        $queueName = 'default_deduplication_queue_default';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RabbitConsumerWithDefaultDeduplicationExample2::class],
            [
                AmqpConnectionFactory::class => AmqpMessagingTestCase::getRabbitConnectionFactory(),
                new RabbitConsumerWithDefaultDeduplicationExample2(),
                DbalConnectionFactory::class => DbalMessagingTestCase::prepareConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::AMQP_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE
                ]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        /** @var MessagePublisher $messagePublisher */
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send message with specific MESSAGE_ID
        $messageId = 'msg-123-default-' . Uuid::uuid4()->toString();
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            [MessageHeaders::MESSAGE_ID => $messageId]
        );
        
        // Run consumer
        $ecotoneLite->run('rabbit_default_deduplication_consumer2', ExecutionPollingMetadata::createWithTestingSetup());

        // Verify message processed
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('rabbit.getDefaultProcessedMessages2'));

        // Send same message with same MESSAGE_ID
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            [MessageHeaders::MESSAGE_ID => $messageId]
        );

        // Run consumer again
        $ecotoneLite->run('rabbit_default_deduplication_consumer2', ExecutionPollingMetadata::createWithTestingSetup());

        // Verify message NOT processed again (still only one message)
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('rabbit.getDefaultProcessedMessages2'));

        // Send message with different MESSAGE_ID
        $messagePublisher->sendWithMetadata(
            'test-payload-2',
            'application/text',
            [MessageHeaders::MESSAGE_ID => 'msg-456-default-' . Uuid::uuid4()->toString()]
        );

        // Run consumer
        $ecotoneLite->run('rabbit_default_deduplication_consumer2', ExecutionPollingMetadata::createWithTestingSetup());

        // Verify new message IS processed
        $this->assertEquals(['test-payload-1', 'test-payload-2'], $ecotoneLite->sendQueryWithRouting('rabbit.getDefaultProcessedMessages2'));
    }

    public function test_deduplication_works_independently_across_different_consumers(): void
    {
        $queueName = 'deduplication_queue_independent';

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RabbitConsumerWithIndependentDeduplicationExample::class],
            [
                AmqpConnectionFactory::class => AmqpMessagingTestCase::getRabbitConnectionFactory(),
                new RabbitConsumerWithIndependentDeduplicationExample(),
                DbalConnectionFactory::class => DbalMessagingTestCase::prepareConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::AMQP_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE
                ]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        /** @var MessagePublisher $messagePublisher */
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send message with custom header value 'order-123-independent'
        $customOrderId1 = 'order-123-independent-' . Uuid::uuid4()->toString();
        $messagePublisher->sendWithMetadata(
            'test-payload-1',
            'application/text',
            ['customOrderId' => $customOrderId1]
        );

        // Send message with different custom header value 'order-456-independent'
        $customOrderId2 = 'order-456-independent-' . Uuid::uuid4()->toString();
        $messagePublisher->sendWithMetadata(
            'test-payload-2',
            'application/text',
            ['customOrderId' => $customOrderId2]
        );

        // Run consumer to process first message
        $ecotoneLite->run('rabbit_independent_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup()->withHandledMessageLimit(1));

        // Verify first message processed
        $this->assertEquals(['test-payload-1'], $ecotoneLite->sendQueryWithRouting('rabbit.getIndependentProcessedMessages'));

        // Run consumer to process second message
        $ecotoneLite->run('rabbit_independent_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup()->withHandledMessageLimit(1));

        // Verify both messages processed (different custom header values)
        $this->assertEquals(['test-payload-1', 'test-payload-2'], $ecotoneLite->sendQueryWithRouting('rabbit.getIndependentProcessedMessages'));

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
        $ecotoneLite->run('rabbit_independent_deduplication_consumer', ExecutionPollingMetadata::createWithTestingSetup()->withHandledMessageLimit(2));

        // Verify no duplicate processing occurred (still only 2 messages)
        $this->assertEquals(['test-payload-1', 'test-payload-2'], $ecotoneLite->sendQueryWithRouting('rabbit.getIndependentProcessedMessages'));
    }
}
