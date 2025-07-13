<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\Attribute\AmqpConsumer;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerAttributeExample;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerWithFailStrategyAttributeExample;

/**
 * @internal
 */
/**
 * licence Enterprise
 * @internal
 */
#[CoversClass(AmqpConsumer::class)]
final class AmqpConsumerAttributeTest extends AmqpMessagingTestCase
{
    public function test_throwing_exception_if_no_licence_for_amqp_consumer_attribute(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
        );
    }

    public function test_having_consumer_without_publisher(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLiteConsumer = EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE])),
            licenceKey: LicenceTesting::VALID_LICENCE
        );
        $ecotoneLitePublisher = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLitePublisher->getGateway(MessagePublisher::class);
        $messagePublisher->send($payload);

        $ecotoneLiteConsumer->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload], $ecotoneLiteConsumer->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLiteConsumer->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload], $ecotoneLiteConsumer->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }

    public function test_adding_product_to_shopping_cart_with_publisher_and_consumer(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->send($payload);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }

    public function test_defining_custom_failure_strategy(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerWithFailStrategyAttributeExample::class],
            [
                new AmqpConsumerWithFailStrategyAttributeExample(),
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper("*")
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $this->assertEquals([], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: true));
        $this->assertEquals([], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }

    public function test_consuming_multiple_messages_from_queue(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload1 = 'message1';
        $payload2 = 'message2';
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->send($payload1);
        $messagePublisher->send($payload2);

        // Consume first message
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload1], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Consume second message
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload1, $payload2], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }
}
