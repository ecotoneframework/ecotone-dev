<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Test;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Console\ConsoleCommandResultSet;
use Ecotone\Api\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleCommandResultTest extends TestCase
{
    public function test_the_result_set_a_console_command_returns_is_read_in_a_flow_test(): void
    {
        $orders = new class () {
            #[ConsoleCommand('app:orders:list')]
            public function list(string $status): ConsoleCommandResultSet
            {
                return ConsoleCommandResultSet::create(['Order', 'Status'], [['o-1', $status]]);
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$orders::class], [$orders]);

        $result = $ecotone->resultOfConsoleCommand('app:orders:list', ['status' => 'placed']);

        self::assertSame([['Order', 'Status'], [['o-1', 'placed']]], [$result->columnHeaders, $result->rows]);
    }

    public function test_a_console_command_that_returns_nothing_has_no_result(): void
    {
        $orders = new class () {
            public array $archived = [];

            #[ConsoleCommand('app:orders:archive')]
            public function archive(string $orderId): void
            {
                $this->archived[] = $orderId;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$orders::class], [$orders]);

        self::assertNull($ecotone->resultOfConsoleCommand('app:orders:archive', ['orderId' => 'o-1']));
        self::assertSame(['o-1'], $orders->archived);
    }
}
