<?php

declare(strict_types=1);

namespace Tests\Ordering;

use App\Ordering\FailOrder;
use App\Ordering\MarkSeatHeld;
use App\Ordering\Order;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\ConcurrencyException;

/**
 * @internal
 */
final class OrderConcurrencyTest extends StorefrontFlowTestCase
{
    private string $racingFailure;

    protected function storefrontServices(): array
    {
        $racingFailure = new class () {
            public ?string $failsOrder = null;

            #[Around(pointcut: Order::class . '::markSeatHeld')]
            public function failTheOrderWhileTheHeldSeatIsBeingMarked(MethodInvocation $invocation, #[Reference] CommandBus $commandBus): mixed
            {
                if ($this->failsOrder !== null) {
                    $orderId = $this->failsOrder;
                    $this->failsOrder = null;
                    $commandBus->send(new FailOrder(orderId: $orderId, reason: 'the rush deadline passed'));
                }

                return $invocation->proceed();
            }
        };
        $this->racingFailure = $racingFailure::class;

        return [$this->racingFailure];
    }

    public function test_a_late_held_reply_racing_a_failure_of_the_same_order_is_refused_instead_of_overwriting_it(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->app->make($this->racingFailure)->failsOrder = $orderId;

        try {
            $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));
            self::fail('The second save of the order was not refused.');
        } catch (ConcurrencyException $exception) {
            self::assertStringStartsWith(sprintf('Aggregate %s %s was loaded at version 1, but', Order::class, $orderId), $exception->getMessage());
        }

        self::assertNotSame('seats_held', $this->order($orderId)['status']);
    }

    public function test_an_order_changed_one_command_after_another_saves_every_change(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');

        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new FailOrder(orderId: $orderId, reason: 'the rush deadline passed'));

        $order = $this->order($orderId);
        self::assertSame('failed', $order['status']);
        self::assertCount(2, $order['heldSeats']);
    }
}
