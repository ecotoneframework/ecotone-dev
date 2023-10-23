<?php

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalInboundChannelAdapterBuilder;
use Ecotone\Dbal\DbalOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\MessagePoller;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\ComponentTestBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * @internal
 */
class ChannelAdapterTest extends DbalMessagingTestCase
{
    public function test_sending_and_receiving_message()
    {
        $queueName = Uuid::uuid4()->toString();
        $requestChannelName = Uuid::uuid4()->toString();
        $requestChannel = QueueChannel::create();
        $timeoutInMilliseconds = 1;
        $componentTestBuilder = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(DbalConnectionFactory::class, $this->getConnectionFactory());

        /** @var MessagePoller $inboundChannelAdapter */
        $inboundChannelAdapter = $componentTestBuilder
            ->build(DbalInboundChannelAdapterBuilder::createWith(
                Uuid::uuid4()->toString(),
                $queueName,
                $requestChannelName
            )
                ->withReceiveTimeout($timeoutInMilliseconds));

        $outboundChannelAdapter = $componentTestBuilder->build(DbalOutboundChannelAdapterBuilder::create($queueName));

        $payload = 'some';
        $outboundChannelAdapter->handle(MessageBuilder::withPayload($payload)->build());

        $receivedMessage = $inboundChannelAdapter->receiveWithTimeout($timeoutInMilliseconds);
        $this->assertNotNull($receivedMessage, 'Not received message');
        $this->assertEquals($payload, $receivedMessage->getPayload());

        $this->assertNull($inboundChannelAdapter->receiveWithTimeout($timeoutInMilliseconds), 'Received message twice instead of one');
    }
}
