<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Test\Ecotone\EventSourcing\Projecting\App\ConsoleProcessTrait;

class ProjectingConcurrencyTest extends TestCase
{
    use ConsoleProcessTrait;

    private static ConfiguredMessagingSystem $ecotone;

    public function setUp(): void
    {
        self::$ecotone = self::bootEcotone();
    }

    public function testItCanPlaceOrder(): void
    {
        $orderId = uniqid('order-');
        $process1 = $this->placeOrder($orderId);

        $process1->wait();
        self::assertSame(Command::SUCCESS, $process1->getExitCode());

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
    public function testInterleavedCommandsSeesGaps(): void
    {
        $orderId1 = uniqid('order-');
        $orderId2 = uniqid('order-');
        $tx1 = $this->placeOrder(
            $orderId1,
            manualProjection: true
        );
        $tx1->waitUntil($this->waitingToExecuteProjection(...));

        $tx2 = $this->placeOrder(
            $orderId2,
        );
        $tx2->wait();
        self::assertSame(Command::SUCCESS, $tx2->getExitCode());

        self::assertAnOrderIsPlacedWithId($orderId2);

        $this->continueProcess($tx1);
        $tx1->wait();
        self::assertSame(Command::SUCCESS, $tx1->getExitCode());
        self::assertAnOrderIsPlacedWithId($orderId1);
    }

    public static function assertAnOrderIsPlacedWithId(string $orderId): void
    {
        $retries = 0;
        $order = self::$ecotone->getQueryBus()->sendWithRouting('order.get', $orderId);
        while ($order === null && $retries < 3) {
            $retries++;
            usleep(100_000);
            $order = self::$ecotone->getQueryBus()->sendWithRouting('order.get', $orderId);
        }
        self::assertNotNull($order, "Failed asserting that order with ID $orderId is placed");
    }
}