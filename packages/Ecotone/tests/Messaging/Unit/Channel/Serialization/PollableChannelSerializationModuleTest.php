<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel\Serialization;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Ecotone\Messaging\Unit\Handler\Logger\LoggerExample;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrder;

/**
 * @internal
 */
final class PollableChannelSerializationModuleTest extends TestCase
{
    public function test_serializing_message_using_default_serialization()
    {
        $this->markTestSkipped('skipped');
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService()],
            [
                SimpleMessageChannelWithSerializationBuilder::createQueueChannel('orders'),
            ]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $this->assertSame(
            serialize(new PlaceOrder('1')),
            $ecotoneLite->getMessageChannel('orders')->receive()->getPayload()
        );
    }

    /**
     * @param string[] $classesToResolve
     * @param object[] $services
     * @param MessageChannelBuilder[] $channelBuilders
     * @param object[] $extensionObjects
     */
    private function bootstrapEcotone(array $classesToResolve, array $services, array $channelBuilders, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classesToResolve,
            $services,
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects($extensionObjects),
            enableAsynchronousProcessing: $channelBuilders
        );
    }
}
