<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Api\Amqp\AmqpMessagePublisherConfiguration;
use Ecotone\Api\Amqp\AmqpQueue;
use Ecotone\Api\Amqp\RabbitConsumer;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\ChannelTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;
use Symfony\Component\Uid\Uuid;
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
#[CoversClass(RabbitConsumer::class)]
final class AmqpConsumerAttributeTest extends AmqpMessagingTestCase
{
    public function test_throwing_exception_if_no_licence_for_amqp_consumer_attribute(): void
    {
        $this->expectException(LicensingException::class);

        $this->bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE])
        );
    }

    public function test_having_consumer_without_publisher(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLiteConsumer = $this->bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );
        $ecotoneLitePublisher = $this->bootstrapFlowTesting(
            [],
            [
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE])
                ->withExtensionObjects([
                    \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::v7()->toRfc4122();
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
        $ecotoneLite = $this->bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE])
                ->withExtensionObjects([
                    \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::v7()->toRfc4122();
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
        $ecotoneLite = $this->bootstrapFlowTesting(
            [AmqpConsumerWithFailStrategyAttributeExample::class],
            [
                new AmqpConsumerWithFailStrategyAttributeExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE])
                ->withExtensionObjects([
                    \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));
        $this->assertEquals([$payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: true));
        $this->assertEquals([$payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }

    public function test_defining_instant_retries(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = $this->bootstrapFlowTesting(
            [AmqpConsumerWithInstantRetryExample::class],
            [
                new AmqpConsumerWithInstantRetryExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE])
                ->withExtensionObjects([
                    \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));
        $this->assertEquals([$payload, $payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: true));
        $this->assertEquals([$payload, $payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));
    }

    public function test_defining_error_channel(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = $this->bootstrapFlowTesting(
            [AmqpConsumerWithInstantRetryAndErrorChannelExample::class],
            [
                new AmqpConsumerWithInstantRetryAndErrorChannelExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE])
                ->withExtensionObjects([
                    \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withHeaderMapper('*')
                        ->withDefaultRoutingKey($queueName),
                    SimpleMessageChannelBuilder::createQueueChannel('customErrorChannel'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->sendWithMetadata($payload, metadata: ['fail' => true]);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));
        $this->assertEquals([$payload, $payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        // Test that message is not consumed again
        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: true));
        $this->assertEquals([$payload, $payload], $ecotoneLite->sendQueryWithRouting('consumer.getAttributeMessagePayloads'));

        $this->assertNotNull(ChannelTestSupport::of($ecotoneLite)->getMessageChannel('customErrorChannel')->receive());
    }

    public function test_consuming_multiple_messages_from_queue(): void
    {
        $endpointId = 'amqp_consumer_attribute';
        $queueName = 'test_queue';
        $ecotoneLite = $this->bootstrapFlowTesting(
            [AmqpConsumerAttributeExample::class],
            [
                new AmqpConsumerAttributeExample(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE])
                ->withExtensionObjects([
                    \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
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
        $ecotoneLite = $this->bootstrapFlowTesting(
            [AmqpConsumerAttributeWithObject::class, StdClassConvert::class],
            [
                new AmqpConsumerAttributeWithObject(), new StdClassConvert(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::AMQP_PACKAGE])
                ->withExtensionObjects([
                    \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
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
