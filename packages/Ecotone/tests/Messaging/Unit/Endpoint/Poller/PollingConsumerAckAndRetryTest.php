<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Endpoint\Poller;

use Ecotone\Api\InstantRetryConfiguration;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceActivator;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class PollingConsumerAckAndRetryTest extends TestCase
{
    public function test_retry_template_does_not_intercept_an_exception_thrown_while_handling_a_message(): void
    {
        $handler = new AlwaysThrowingHandler();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [AlwaysThrowingHandler::class],
            [$handler],
            ServiceConfiguration::createWithDefaults()->withExtensionObjects([
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                SimpleMessageChannelBuilder::createQueueChannel(AlwaysThrowingHandler::CHANNEL),
                PollingMetadata::create(AlwaysThrowingHandler::ENDPOINT_ID)
                    ->withTestingSetup(failAtError: true)
                    ->setConnectionRetryTemplate(RetryTemplateBuilder::fixedBackOff(1)->maxRetries(1)),
            ]),
        );

        $ecotone->sendDirectToChannel(AlwaysThrowingHandler::CHANNEL, 'somePayload');

        $exceptionThrown = false;
        try {
            $ecotone->run(AlwaysThrowingHandler::ENDPOINT_ID);
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);
        $this->assertSame(1, $handler->calledTimes);
    }

    public function test_retry_template_retries_an_exception_thrown_while_receiving_a_message(): void
    {
        $handler = new NeverCalledHandler();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [NeverCalledHandler::class],
            [$handler],
            ServiceConfiguration::createWithDefaults()->withExtensionObjects([
                SimpleMessageChannelBuilder::createExceptionChannel(ExceptionalQueueChannel::createWithExceptionOnReceive(NeverCalledHandler::CHANNEL)),
                PollingMetadata::create(NeverCalledHandler::ENDPOINT_ID)
                    ->withTestingSetup(failAtError: false)
                    ->setConnectionRetryTemplate(RetryTemplateBuilder::fixedBackOff(1)->maxRetries(2)),
            ]),
        );

        try {
            $ecotone->run(NeverCalledHandler::ENDPOINT_ID);
        } catch (RuntimeException $e) {
        }

        $this->assertFalse($handler->wasCalled);
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class AlwaysThrowingHandler
{
    public const CHANNEL = 'pollingConsumerRetry.channel';
    public const ENDPOINT_ID = 'pollingConsumerRetry.endpoint';

    public int $calledTimes = 0;

    #[ServiceActivator(self::CHANNEL, self::ENDPOINT_ID)]
    public function handle(string $payload): void
    {
        $this->calledTimes++;

        throw new RuntimeException('error during handling');
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class NeverCalledHandler
{
    public const CHANNEL = 'pollingConsumerReceiveRetry.channel';
    public const ENDPOINT_ID = 'pollingConsumerReceiveRetry.endpoint';

    public bool $wasCalled = false;

    #[ServiceActivator(self::CHANNEL, self::ENDPOINT_ID)]
    public function handle(string $payload): void
    {
        $this->wasCalled = true;
    }
}
