<?php

namespace Test\Ecotone\Messaging\Unit;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageCompareService;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * Class MessagingTest
 * @package Ecotone\Messaging
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
abstract class MessagingTest extends TestCase
{
    public const FIXTURE_DIR = __DIR__ . '/../Fixture';

    public const ROOT_DIR = self::FIXTURE_DIR . '/../../..';

    public function assertMessages(Message $message, Message $toCompareWith): void
    {
        if (! MessageCompareService::areSameMessagesIgnoringIdAndTimestamp($message, $toCompareWith)) {
            $this->assertEquals($message, $toCompareWith);
        } else {
            $this->assertTrue(true);
        }
    }

    public function assertMultipleMessages(array $messages, array $messagesToCompareWith): void
    {
        $messagesAmount = count($messages);
        Assert::assertCount($messagesAmount, $messagesToCompareWith, 'Amount of messages is different');

        for ($i = 0; $i < $messagesAmount; $i++) {
            $this->assertMessages($messages[$i], $messagesToCompareWith[$i]);
        }
    }
}
