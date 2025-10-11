<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\Attribute\RabbitConsumer;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\CoversClass;
use Ramsey\Uuid\Uuid;
use stdClass;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerAttributeExample;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerAttributeWithObject;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerWithFailStrategyAttributeExample;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerWithInstantRetryAndErrorChannelExample;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerWithInstantRetryExample;

/**
 * @internal
 */
/**
 * licence Enterprise
 * @internal
 */
#[CoversClass(RabbitConsumer::class)]
final class AmqpConsumerAttributeTest extends AmqpMessagingTestCase
{
    public function test_throwing_exception_if_no_licence_for_amqp_consumer_attribute(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                ...$this->getConnectionFactoryReferences(),
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
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE])),
            licenceKey: LicenceTesting::VALID_LICENCE
        );
        $ecotoneLitePublisher = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                ...$this->getConnectionFactoryReferences(),
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
                ...$this->getConnectionFactoryReferences(),
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
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
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
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerWithInstantRetryExample::class],
            [
                new AmqpConsumerWithInstantRetryExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $this->assertEquals([$payload, $payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: true));
        $this->assertEquals([$payload, $payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }

    public function test_defining_error_channel(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerWithInstantRetryAndErrorChannelExample::class],
            [
                new AmqpConsumerWithInstantRetryAndErrorChannelExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
                    SimpleMessageChannelBuilder::createQueueChannel('customErrorChannel'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $this->assertEquals([$payload, $payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(failAtError: true));
        $this->assertEquals([$payload, $payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        $this->assertNotNull($ecotoneLite->getMessageChannel('customErrorChannel')->receive());
    }

    public function test_consuming_multiple_messages_from_queue(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                ...$this->getConnectionFactoryReferences(),
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

    public function test_consuming_with_converter(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AmqpConsumerAttributeWithObject::class, StdClassConvert::class],
            [
                new AmqpConsumerAttributeWithObject(), new StdClassConvert(),
                ...$this->getConnectionFactoryReferences(),
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
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->send($payload1);

        // Consume first message
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals($payload1, $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads')[0]->data);
    }
}

#[MediaTypeConverter]
class StdClassConvert implements \Ecotone\Messaging\Conversion\Converter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        $stdClass = new stdClass();
        $stdClass->data = $source;

        return $stdClass;
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $targetType->equals(Type::object(stdClass::class));
    }
}
