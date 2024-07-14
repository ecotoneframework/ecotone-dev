<?php

namespace Test\Ecotone\Messaging\Unit\Channel;

use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Class QueueChannelTest
 * @package Ecotone\Messaging\Channel
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 */
class QueueChannelTest extends TestCase
{
    public function test_sending_and_receiving_message_in_last_in_first_out_order()
    {
        $queueChannel = QueueChannel::create();

        $firstMessage = MessageBuilder::withPayload('a')->build();
        $secondMessage = MessageBuilder::withPayload('b')->build();

        $queueChannel->send($firstMessage);
        $queueChannel->send($secondMessage);

        $this->assertEquals($firstMessage, $queueChannel->receive());
        $this->assertEquals($secondMessage, $queueChannel->receive());
    }

    public function test_returning_null_when_queue_is_empty()
    {
        $queueChannel = QueueChannel::create();

        $this->assertNull($queueChannel->receive());
    }
}
