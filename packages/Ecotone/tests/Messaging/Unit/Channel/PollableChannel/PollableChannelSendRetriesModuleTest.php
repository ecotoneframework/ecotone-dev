<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel\PollableChannel;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\PollableChannel\GlobalPollableChannelConfiguration;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Ecotone\Messaging\Unit\Handler\Logger\LoggerExample;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrder;

/**
 * @internal
 */
final class PollableChannelSendRetriesModuleTest extends TestCase
{
    public function test_retrying_on_failure_with_success()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                ExceptionalQueueChannel::createWithExceptionOnSend('orders', 1),
            ]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $message = $ecotoneLite->getMessageChannel('orders')->receive();

        $this->assertNotNull($message);
        $this->assertCount(3, $loggerExample->getInfo());
    }

    public function test_retrying_two_time_on_failure_and_recovering()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                ExceptionalQueueChannel::createWithExceptionOnSend('orders', 2),
            ]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $message = $ecotoneLite->getMessageChannel('orders')->receive();

        $this->assertNotNull($message);
        $this->assertCount(4, $loggerExample->getInfo());
    }

    public function test_retrying_exceeded_and_fails()
    {
        $loggerExample = LoggerExample::create();

        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                ExceptionalQueueChannel::createWithExceptionOnSend('orders', 3),
            ]
        );

        $exception = false;
        try {
            $ecotoneLite->sendCommand(new PlaceOrder('1'));
        } catch (Exception $exception) {
            $exception = true;
        }

        $message = $ecotoneLite->getMessageChannel('orders')->receive();

        $this->assertTrue($exception);
        $this->assertNull($message);
        $this->assertCount(4, $loggerExample->getInfo());
        $this->assertCount(1, $loggerExample->getError());
    }

    public function test_with_custom_retry_strategy()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                ExceptionalQueueChannel::createWithExceptionOnSend('orders', 2),
            ],
            [
                PollableChannelConfiguration::create('orders', RetryTemplateBuilder::fixedBackOff(1)->maxRetryAttempts(1)->build()),
            ]
        );

        $this->expectException(RuntimeException::class);

        $ecotoneLite->sendCommand(new PlaceOrder('1'));
    }

    public function test_disabling_retries()
    {
        $loggerExample = LoggerExample::create();

        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                ExceptionalQueueChannel::createWithExceptionOnSend('orders', 1),
            ],
            [
                PollableChannelConfiguration::neverRetry('orders'),
            ]
        );

        $exception = false;
        try {
            $ecotoneLite->sendCommand(new PlaceOrder('1'));
        } catch (Exception $exception) {
            $exception = true;
        }

        $message = $ecotoneLite->getMessageChannel('orders')->receive();

        $this->assertTrue($exception);
        $this->assertNull($message);
        $this->assertCount(1, $loggerExample->getError());
    }

    public function test_sending_to_dead_letter_on_failure()
    {
        $loggerExample = LoggerExample::create();

        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                ExceptionalQueueChannel::createWithExceptionOnSend('orders', 1),
                SimpleMessageChannelBuilder::createQueueChannel('deadLetter'),
            ],
            [
                PollableChannelConfiguration::neverRetry('orders')
                    ->withErrorChannel('deadLetter'),
            ]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $this->assertNull($ecotoneLite->getMessageChannel('orders')->receive());
        $this->assertNotNull($ecotoneLite->getMessageChannel('deadLetter')->receive());
    }

    public function test_sending_to_dead_letter_on_failure_using_global_configuration()
    {
        $loggerExample = LoggerExample::create();

        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                ExceptionalQueueChannel::createWithExceptionOnSend('orders', 1),
                SimpleMessageChannelBuilder::createQueueChannel('deadLetter'),
            ],
            [
                GlobalPollableChannelConfiguration::neverRetry('orders')
                    ->withErrorChannel('deadLetter'),
            ]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $this->assertNull($ecotoneLite->getMessageChannel('orders')->receive());
        $this->assertNotNull($ecotoneLite->getMessageChannel('deadLetter')->receive());
    }

    public function test_on_success_recover_message_is_not_sent_to_dlq()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                ExceptionalQueueChannel::createWithExceptionOnSend('orders', 2),
                SimpleMessageChannelBuilder::createQueueChannel('deadLetter'),
            ],
            [
                PollableChannelConfiguration::create(
                    'orders',
                    RetryTemplateBuilder::fixedBackOff(1)
                        ->maxRetryAttempts(2)
                        ->build()
                )
                    ->withErrorChannel('deadLetter'),
            ]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $this->assertNotNull($ecotoneLite->getMessageChannel('orders')->receive());
        $this->assertNull($ecotoneLite->getMessageChannel('deadLetter')->receive());
    }


    /**
     * @param string[] $classesToResolve
     * @param object[] $services
     * @param MessageChannelBuilder[] $channelBuilders
     * @param object[] $extensionObjects
     */
    private function bootstrapEcotone(array $classesToResolve, array $services, array $channelBuilders, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classesToResolve,
            $services,
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects($extensionObjects),
            enableAsynchronousProcessing: $channelBuilders
        );
    }
}
