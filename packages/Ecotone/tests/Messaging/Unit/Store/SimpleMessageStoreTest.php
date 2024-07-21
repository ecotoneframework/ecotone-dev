<?php

namespace Test\Ecotone\Messaging\Unit\Store;

use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Store\SimpleMessageStore;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Class SimpleMessageStoreTest
 * @package Ecotone\Messaging\Store
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class SimpleMessageStoreTest extends TestCase
{
    public function test_adding_message()
    {
        $message = MessageBuilder::withPayload('some')
                                    ->build();

        $simpleMessageStore = SimpleMessageStore::createEmpty();

        $this->assertNull($simpleMessageStore->getSingleMessage(Uuid::fromString($message->getHeaders()->get(MessageHeaders::MESSAGE_ID))));
        $simpleMessageStore->addSingleMessage($message);

        $this->assertEquals(
            $message,
            $simpleMessageStore->getSingleMessage(Uuid::fromString($message->getHeaders()->get(MessageHeaders::MESSAGE_ID)))
        );
        $this->assertEquals(1, $simpleMessageStore->getSingleMessagesCount());
    }

    public function add_message_to_group()
    {
        $message = MessageBuilder::withPayload('some')
            ->build();

        $simpleMessageStore = SimpleMessageStore::createEmpty();

        $groupId = 'some-group';
        $simpleMessageStore->addMessageToGroup($groupId, $message);

        $this->assertEquals(
            $message,
            $simpleMessageStore->pollMessageFromGroup($groupId)
        );
    }
}
