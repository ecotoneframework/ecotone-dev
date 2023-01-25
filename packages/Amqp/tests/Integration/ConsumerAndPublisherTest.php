<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\Configuration\AmqpMessageConsumerConfiguration;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerExample;

/**
 * @internal
 */
final class ConsumerAndPublisherTest extends AmqpMessagingTest
{
    public function testing_sending_message_using_publisher_and_receiving_using_consumer()
    {
        $endpointId = 'asynchronous_endpoint';
        $queueName = Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [AmqpConsumerExample::class],
            [
                new AmqpConsumerExample(),
                AmqpConnectionFactory::class => $this->getRabbitConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessageConsumerConfiguration::create($endpointId, $queueName),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withDefaultRoutingKey($queueName),
                ])
        );

        $payload = 'random_payload';
        $messagePublisher = $ecotoneLite->getMessagePublisher();
        $messagePublisher->send($payload);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        $this->assertEquals([$payload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        $this->assertEquals([$payload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));
    }
}
