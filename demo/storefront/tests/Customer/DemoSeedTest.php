<?php

declare(strict_types=1);

namespace Tests\Customer;

use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @internal
 */
final class DemoSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('orders')->delete();
        DB::table('customers')->delete();
    }

    public function test_demo_seed_registers_the_three_demo_customers(): void
    {
        self::assertSame(0, Artisan::call('demo:seed'));

        self::assertSame(
            [['Ada Lovelace', 'ada@example.com'], ['Grace Hopper', 'grace@example.com'], ['Linus Bounce', 'linus@bounce.example']],
            $this->customers(),
        );
    }

    public function test_demo_seed_run_again_registers_no_customer_twice(): void
    {
        self::assertSame(0, Artisan::call('demo:seed'));

        self::assertSame(0, Artisan::call('demo:seed'));

        self::assertCount(3, $this->customers());
    }

    private function customers(): array
    {
        return array_map(
            fn (array $customer) => [$customer['name'], $customer['email']],
            $this->app->make(QueryBus::class)->sendWithRouting('customers.list'),
        );
    }
}
