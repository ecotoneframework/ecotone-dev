<?php

namespace Test\Ecotone\Messaging\Unit\Channel;

use Ecotone\Messaging\Channel\InProcessChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Handler\HandlerRedirectingToChannel;
use Test\Ecotone\Messaging\Fixture\Handler\NoReturnMessageHandler;

class InProcessChannelTest extends TestCase
{
    public function test_publishing_message()
    {
        $directChannel = InProcessChannel::createDirectChannel();

        $messageHandler = NoReturnMessageHandler::create();
        $directChannel->subscribe($messageHandler);

        $directChannel->send(MessageBuilder::withPayload('test')->build());

        $this->assertTrue($messageHandler->wasCalled(), 'Message handler for direct channel was not called');
    }

    public function test_it_can_send_message_handler_with_output_channel()
    {
        $executorChannel = InProcessChannel::createDirectChannel();
        $inProcessChannel1 = InProcessChannel::createDirectChannel();
        $inProcessChannel2 = InProcessChannel::createDirectChannel();
        $messageHandler1 = new HandlerRedirectingToChannel($inProcessChannel1);
        $messageHandler2 = new HandlerRedirectingToChannel($inProcessChannel2);
        $lastHandler = NoReturnMessageHandler::create();
        $executorChannel->subscribe($messageHandler1);
        $inProcessChannel1->subscribe($messageHandler2);
        $inProcessChannel2->subscribe($lastHandler);

        $executorChannel->send(MessageBuilder::withPayload('some')->build());


        $this->assertTrue($lastHandler->wasCalled(), 'Message handler for direct channel was not called');
    }
}
