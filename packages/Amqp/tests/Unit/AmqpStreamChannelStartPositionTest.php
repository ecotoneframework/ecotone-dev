<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Unit;

use Ecotone\Api\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpStreamChannelStartPositionTest extends TestCase
{
    public function test_a_stream_channel_starting_at_next_is_refused_naming_the_positions_that_replay(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("AMQP stream channel `orders` cannot start at `next`: RabbitMQ delivers a `next` consumer only what is published after it attaches, so every message published before the consumer's first poll, or between two of its runs, is never delivered. Start at `first`, at `last`, at an absolute offset such as `100`, or at a timestamp such as `1700000000`; after the first commit the consumer resumes from its committed offset whichever start position it was given.");

        AmqpStreamChannelBuilder::create('orders', 'next');
    }

    public function test_the_other_start_positions_are_accepted(): void
    {
        foreach (['first', 'last', '100', '1700000000'] as $startPosition) {
            $this->assertSame('orders', AmqpStreamChannelBuilder::create('orders', $startPosition)->getMessageChannelName());
        }
    }
}
