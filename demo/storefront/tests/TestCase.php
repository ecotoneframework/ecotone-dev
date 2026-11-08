<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        $this->endTheIdleConnectionsThatEveryBootedEcotoneApplicationOfThisProcessLeavesOpen();
        parent::tearDown();
    }

    private function endTheIdleConnectionsThatEveryBootedEcotoneApplicationOfThisProcessLeavesOpen(): void
    {
        DB::statement(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = current_database() AND application_name = current_setting('application_name') AND state = 'idle' AND pid NOT IN (pg_backend_pid(), ?)",
            [DB::connection('payment_provider')->selectOne('SELECT pg_backend_pid() AS pid')->pid],
        );
    }
}
