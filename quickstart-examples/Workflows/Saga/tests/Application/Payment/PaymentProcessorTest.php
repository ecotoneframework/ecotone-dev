<?php

declare(strict_types=1);

namespace Tests\App\Workflow\Saga\Payment;

use App\Workflow\Saga\Application\Payment\Command\TakePayment;
use App\Workflow\Saga\Application\Payment\Event\PaymentFailed;
use App\Workflow\Saga\Application\Payment\Event\PaymentWasSuccessful;
use App\Workflow\Saga\Application\Payment\PaymentService;
use App\Workflow\Saga\Application\Payment\PaymentProcessor;
use Ecotone\Lite\EcotoneLite;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class PaymentProcessorTest extends TestCase
{
    public function test_it_publish_successful_event_when_payment_made()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [PaymentService::class],
            [
                PaymentService::class => new PaymentService(new PaymentProcessor())
            ]
        );

        $this->assertEquals(
            [new PaymentWasSuccessful('123')],
            $ecotoneLite
                ->sendCommandWithRoutingKey('takePayment', new TakePayment('123', Money::EUR(100)))
                ->getRecordedEvents(),
        );
    }

    public function test_it_publish_failure_event_when_payment_was_not_made()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [PaymentService::class],
            [
                PaymentService::class => new PaymentService(new PaymentProcessor(2))
            ]
        );

        $this->assertEquals(
            [new PaymentFailed('123')],
            $ecotoneLite
                ->sendCommandWithRoutingKey('takePayment', new TakePayment('123', Money::EUR(100)))
                ->getRecordedEvents(),
        );
    }

    public function test_it_publish_success_on_second_try()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [PaymentService::class],
            [
                PaymentService::class => new PaymentService(new PaymentProcessor(2))
            ]
        );

        $this->assertEquals(
            [new PaymentWasSuccessful('123')],
            $ecotoneLite
                ->sendCommandWithRoutingKey('takePayment', new TakePayment('123', Money::EUR(100)))
                ->discardRecordedMessages()
                ->sendCommandWithRoutingKey('takePayment', new TakePayment('123', Money::EUR(100)))
                ->getRecordedEvents(),
        );
    }
}