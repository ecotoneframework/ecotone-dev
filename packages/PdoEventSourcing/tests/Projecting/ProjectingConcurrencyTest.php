<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Projecting\App\ConsoleProcessTrait;

/**
 * @internal
 */
class ProjectingConcurrencyTest extends TestCase
{
    use ConsoleProcessTrait;

    private static ConfiguredMessagingSystem $ecotone;

    public function setUp(): void
    {
        self::$ecotone = self::bootEcotone();
    }

    public function test_it_can_place_order(): void
    {
        $orderId = uniqid('order-');
        $process1 = $this->placeOrder($orderId);

        $process1->wait();
        self::assertTrue($process1->isSuccessful(), $process1->getErrorOutput());

        $order = self::$ecotone->getQueryBus()->sendWithRouting('order.get', $orderId);
        self::assertSame([
            'order_id' => $orderId,
            'product' => 'a-book',
            'quantity' => 1,
            'status' => 'placed',
            'reason' => null,
        ], $order);
    }

    /**
     * -- TX1
     * insert 10
     *
     * -- TX2
     * insert 11
     * acquire lock
     * move to position 11 with gap on 10
     * commit
     *
     * -- TX1
     * acquire lock
     * sees position 11 with gap on 10
     * processes event at position 10
     * releases lock
     */
    public function test_interleaved_commands_sees_gaps(): void
    {
        $orderId1 = uniqid('order-');
        $orderId2 = uniqid('order-');
        $tx1 = $this->placeOrder(
            $orderId1,
            manualProjection: true
        );
        self::assertTrue($tx1->isRunning(), $tx1->getErrorOutput());
        $tx1->waitUntil($this->waitingToExecuteProjection(...));

        $tx2 = $this->placeOrder(
            $orderId2,
        );
        $tx2->wait();
        self::assertTrue($tx2->isSuccessful());

        self::assertAnOrderIsPlacedWithId($orderId2);

        $this->continueProcess($tx1);
        $tx1->wait();
        self::assertTrue($tx1->isSuccessful());
        self::assertAnOrderIsPlacedWithId($orderId1);
    }

    public static function assertAnOrderIsPlacedWithId(string $orderId, int $maxRetries = 3, int $baseDelayMicroseconds = 5_000): void
    {
        $order = null;
        $retries = 0;
        while ($order === null && $retries < $maxRetries) {
            $order = self::$ecotone->getQueryBus()->sendWithRouting('order.get', $orderId);
            if ($order !== null) {
                break;
            }
            usleep($baseDelayMicroseconds * (2 ** $retries));
            $retries++;
        }
        self::assertNotNull($order, "Failed asserting that order with ID $orderId is placed");
    }
}
