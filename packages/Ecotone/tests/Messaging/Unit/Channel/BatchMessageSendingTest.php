<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class BatchMessageSendingTest extends TestCase
{
    public function test_batch_message_sent_to_pollable_channel_is_delivered_as_individual_messages(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
            ],
        );

        $batch = BatchMessage::constructEmpty()
            ->append('first order')
            ->append('second order', ['priority' => 5]);

        $ecotoneLite->getMessageChannel('orders')->send(
            MessageBuilder::withPayload($batch)->build()
        );

        $firstMessage = $ecotoneLite->receiveMessageFrom('orders');
        $secondMessage = $ecotoneLite->receiveMessageFrom('orders');

        $this->assertSame('first order', $firstMessage->getPayload());
        $this->assertSame('second order', $secondMessage->getPayload());
        $this->assertSame(5, $secondMessage->getHeaders()->get('priority'));
        $this->assertNull($ecotoneLite->receiveMessageFrom('orders'));
    }

    public function test_empty_batch_message_delivers_nothing(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
            ],
        );

        $ecotoneLite->getMessageChannel('orders')->send(
            MessageBuilder::withPayload(BatchMessage::constructEmpty())->build()
        );

        $this->assertNull($ecotoneLite->receiveMessageFrom('orders'));
    }
}
