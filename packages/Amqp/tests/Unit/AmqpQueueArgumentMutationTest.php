<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Unit;

use Ecotone\Api\Amqp\AmqpQueue;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpQueueArgumentMutationTest extends TestCase
{
    public function test_arguments_set_as_statements_reach_the_queue(): void
    {
        $queue = AmqpQueue::createWith('ecotone_1000_delay');

        $queue->setArgument('x-message-ttl', 1000);
        $queue->setArgument('x-dead-letter-exchange', 'afterDelay');

        $arguments = $queue->toEnqueueQueue()->getArguments();

        self::assertSame(1000, $arguments['x-message-ttl']);
        self::assertSame('afterDelay', $arguments['x-dead-letter-exchange']);
    }
}
