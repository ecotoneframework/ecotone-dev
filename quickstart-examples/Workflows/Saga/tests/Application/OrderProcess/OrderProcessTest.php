<?php

declare(strict_types=1);

namespace Tests\App\Workflow\Saga\Application\OrderProcess;

use App\Workflow\Saga\Application\Order\Event\OrderWasPlaced;
use App\Workflow\Saga\Application\Order\OrderService;
use App\Workflow\Saga\Application\OrderProcess\Event\OrderProcessWasStarted;
use App\Workflow\Saga\Application\OrderProcess\OrderProcess;
use App\Workflow\Saga\Application\OrderProcess\OrderProcessStatus;
use App\Workflow\Saga\Application\Payment\PaymentService;
use App\Workflow\Saga\Application\Payment\PaymentProcessor;
use App\Workflow\Saga\Infrastructure\StubOrderService;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Scheduling\TimeSpan;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class OrderProcessTest extends TestCase
{
    public function test_starting_order_was_placed_process(): void
    {
        $orderId = '123';
        $totalPrice = Money::EUR(100);
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderProcess::class],
            [
                OrderService::class => new StubOrderService($totalPrice)
            ],
            enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('async')]
        );

        $ecotoneLite->publishEvent(new OrderWasPlaced($orderId));

        $this->assertEquals(
            OrderProcessStatus::PLACED,
            /** @link https://docs.ecotone.tech/modelling/command-handling/identifier-mapping#dynamic-identifier */
            $ecotoneLite->sendQueryWithRouting('orderProcess.getStatus', metadata: ['aggregate.id' => $orderId])
        );

        $this->assertEquals(
            new OrderProcessWasStarted($orderId),
            $ecotoneLite->getRecordedEvents()[1]
        );
    }

    public function test_taking_successful_payment_and_shipping_the_order(): void
    {
        $orderId = '123';
        $totalPrice = Money::EUR(100);
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderProcess::class, PaymentService::class],
            [
                OrderService::class => new StubOrderService($totalPrice),
                PaymentService::class => new PaymentService(new PaymentProcessor())
            ],
            enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('async')]
        );

        $this->assertEquals(
            OrderProcessStatus::READY_TO_BE_SHIPPED,
            $ecotoneLite
                ->publishEvent(new OrderWasPlaced($orderId))
                ->run('async')
                ->sendQueryWithRouting('orderProcess.getStatus', metadata: ['aggregate.id' => $orderId])
        );
    }

    public function test_cancelling_order_after_two_failures(): void
    {
        $orderId = '123';
        $totalPrice = Money::EUR(100);
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderProcess::class, PaymentService::class],
            [
                OrderService::class => new StubOrderService($totalPrice),
                PaymentService::class => new PaymentService(new PaymentProcessor(successAfterAttempt: 3))
            ],
            enableAsynchronousProcessing: [
                // Make Message Channel aware of the delay
                SimpleMessageChannelBuilder::createQueueChannel('async', delayable: true)
            ]
        );

        $this->assertEquals(
            OrderProcessStatus::CANCELLED,
            $ecotoneLite
                ->publishEvent(new OrderWasPlaced($orderId))
                ->releaseAwaitingMessagesAndRunConsumer('async', new TimeSpan(hours: 1))
                ->sendQueryWithRouting('orderProcess.getStatus', metadata: ['aggregate.id' => $orderId])
        );
    }
}