<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\ErrorHandler;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\ErrorHandlerConfiguration;
use Ecotone\Api\EventHandler;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\InstantRetryConfiguration;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Support\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class DelayedRetryBackOffTest extends TestCase
{
    public function test_zero_back_off_retries_within_one_run_and_ends_in_dead_letter(): void
    {
        $sender = new class () {
            public int $attempts = 0;

            #[Asynchronous('async')]
            #[EventHandler('order.completed', endpointId: 'sendOrderConfirmation')]
            public function send(string $orderId): void
            {
                $this->attempts++;
                throw new RuntimeException('SMTP connection refused');
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$sender::class],
            [$sender],
            ServiceConfiguration::createWithDefaults()
                ->withDefaultErrorChannel('errorChannel')
                ->withExtensionObjects([
                    ErrorHandlerConfiguration::createWithDeadLetterChannel('errorChannel', RetryTemplateBuilder::fixedBackOff(0)->maxRetryAttempts(3), 'deadLetter'),
                    InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    SimpleMessageChannelBuilder::createQueueChannel('deadLetter'),
                    SimpleMessageChannelBuilder::createQueueChannel('async'),
                ]),
        );

        $ecotone->publishEventWithRoutingKey('order.completed', 'order-1');
        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $this->assertSame(4, $sender->attempts);
        $this->assertNotNull($ecotone->getMessageChannel('deadLetter')->receive());
    }

    public function test_exponential_back_off_accepts_zero_initial_delay(): void
    {
        $this->assertSame(0, RetryTemplateBuilder::exponentialBackoff(0, 2)->maxRetryAttempts(2)->build()->calculateNextDelay(2));
    }

    public function test_negative_initial_delay_is_rejected_with_the_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry initial delay must be 0 or greater, got -1 ms');

        RetryTemplateBuilder::fixedBackOff(-1);
    }
}
