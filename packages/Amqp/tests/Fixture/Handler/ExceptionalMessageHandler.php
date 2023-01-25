<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\Handler;

use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Endpoint\PollingConsumer\RejectMessageException;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Exception;
use RuntimeException;

/**
 * Class ExceptionalMessageHandler
 * @package Test\Ecotone\Messaging\Fixture\Handler
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class ExceptionalMessageHandler implements MessageHandler
{
    private function __construct(private Exception $exception)
    {
    }

    public static function create(): self
    {
        return new self(new RuntimeException('test error, should be caught'));
    }

    public static function createWithRejectException()
    {
        return new self(new RejectMessageException('test error, should be caught'));
    }

    #[MessageConsumer(endpointId: 'normal_queue')]
    public function handle(Message $message): void
    {
        throw $this->exception;
    }

    public function __toString()
    {
        return self::class;
    }
}
