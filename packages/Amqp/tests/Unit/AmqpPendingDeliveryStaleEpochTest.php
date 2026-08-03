<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Unit;

use Ecotone\Amqp\AmqpPendingDelivery;
use Ecotone\Amqp\AmqpPublisherConfirmations;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Amqp\AmqpContext;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpPendingDeliveryStaleEpochTest extends TestCase
{
    public function test_reconnect_after_publish_is_reported_as_connection_reset_instead_of_settling_against_fresh_channel(): void
    {
        $confirmations = new AmqpPublisherConfirmations();
        $prePublishEpoch = $confirmations->getEpoch();
        $deliveryTag = $confirmations->recordPublishedMessage();

        $confirmations->reset();
        $freshChannelDeliveryTag = $confirmations->recordPublishedMessage();
        $confirmations->recordConfirmation($freshChannelDeliveryTag, multiple: true);

        $pendingDelivery = new AmqpPendingDelivery(
            $this->createStub(AmqpContext::class),
            [['message' => MessageBuilder::withPayload('order published before reconnect')->build(), 'deliveryTag' => $deliveryTag, 'correlationId' => '']],
            timeoutInMilliseconds: 1000,
            channelName: 'orders',
            confirmations: $confirmations,
            confirmationsEpoch: $prePublishEpoch,
        );

        $deliveryResult = $pendingDelivery->awaitDelivery();

        $this->assertFalse($deliveryResult->isSuccessful());
        $this->assertStringContainsString('connection was reset', $deliveryResult->getFailedDeliveries()[0]->getFailureReason());
    }
}
