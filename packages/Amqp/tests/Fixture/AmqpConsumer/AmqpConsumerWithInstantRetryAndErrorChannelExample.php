<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\AmqpConsumer;

use Ecotone\Api\Amqp\RabbitConsumer;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use RuntimeException;

/**
 * licence Enterprise
 */
final class AmqpConsumerWithInstantRetryAndErrorChannelExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[InstantRetry(retryTimes: 1)]
    #[ErrorChannel('customErrorChannel')]
    #[RabbitConsumer('amqp_consumer_attribute', 'test_queue', finalFailureStrategy: FinalFailureStrategy::RESEND)]
    public function handle(#[Payload] string $payload, #[Header('fail')] bool $fail = false): void
    {
        $this->messagePayloads[] = $payload;

        if ($fail) {
            throw new RuntimeException('Failed');
        }
    }

    /**
     * @return string[]
     */
    #[QueryHandler('consumer.getAttributeMessagePayloads')]
    public function getMessagePayloads(): array
    {
        return $this->messagePayloads;
    }
}
