<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
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
        if (str_starts_with(getenv('DATABASE_DSN') ?: '', 'sqlite')) {
            self::markTestSkipped('SQLite serialises writers with no lock-wait timeout, so the two-process gap-interleaving scenario this test drives cannot be proven the way it can on Postgres/MySQL/MariaDB.');
        }

        self::$ecotone = self::bootEcotone();
        self::$ecotone->getServiceFromContainer('Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager')->initializeAll();
        self::$ecotone->getServiceFromContainer('Ecotone\Dbal\Connection\DbalConnectionFactory')->establishConnection()->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS order_list_projection (
                order_id VARCHAR(255) PRIMARY KEY,
                product VARCHAR(255) NOT NULL,
                quantity INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                reason VARCHAR(255)
            )
            SQL);
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
