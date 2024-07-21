<?php

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalInboundChannelAdapterBuilder;
use Ecotone\Dbal\DbalOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\ComponentTestBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class ChannelAdapterTest extends DbalMessagingTestCase
{
    public function test_sending_and_receiving_message()
    {
        $queueName = Uuid::uuid4()->toString();
        $inboundChannelName = Uuid::uuid4()->toString();
        $inboundChannel = QueueChannel::create();
        $timeoutInMilliseconds = 1;

        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create($inboundChannelName, $inboundChannel))
            ->withReference(DbalConnectionFactory::class, $this->getConnectionFactory())
            ->withInboundChannelAdapter(
                DbalInboundChannelAdapterBuilder::createWith(
                    $endpointId = Uuid::uuid4()->toString(),
                    $queueName,
                    $inboundChannelName
                )
                    ->withReceiveTimeout($timeoutInMilliseconds)
            )
            ->withPollingMetadata(
                PollingMetadata::create($endpointId)->withTestingSetup()
            )
            ->withMessageHandler(
                DbalOutboundChannelAdapterBuilder::create($queueName)
                    ->withInputChannelName($outboundChannelName = 'outboundChannel')
            )
            ->build();

        $payload = 'some';
        $messaging->sendMessageDirectToChannel(
            $outboundChannelName,
            MessageBuilder::withPayload($payload)->build()
        );

        $messaging->run($endpointId);
        $receivedMessage = $inboundChannel->receive();
        $this->assertNotNull($receivedMessage, 'Not received message');
        $this->assertEquals($payload, $receivedMessage->getPayload());

        $this->assertNull($inboundChannel->receive(), 'Received message twice instead of one');
    }
}
