<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\Connection\AmqpExtConnectionFactory;
use Ecotone\Amqp\Connection\AmqpLibConnectionFactory;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpConnectionReference;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\MessageBuilder;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpConnectionReferenceTest extends AmqpMessagingTestCase
{
    public function test_channel_publishes_and_consumes_using_only_ecotone_owned_amqp_connection_classes(): void
    {
        $queue_name = 'amqp_connection_reference_' . Uuid::v7()->toRfc4122();
        $connection_factory = new AmqpExtConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);

        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                AmqpConnectionReference::DEFAULT => $connection_factory,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE])
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create($queue_name),
                ]),
        );

        $ecotone->getMessageChannel($queue_name)->send(MessageBuilder::withPayload('milk')->build());

        self::assertSame('milk', $ecotone->getMessageChannel($queue_name)->receive()->getPayload());
    }

    public function test_amqp_lib_connection_factory_establishes_a_real_broker_connection(): void
    {
        $connection_factory = new AmqpLibConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);

        $context = $connection_factory->createContext();

        self::assertTrue($context->getLibChannel()->is_open());
    }
}
