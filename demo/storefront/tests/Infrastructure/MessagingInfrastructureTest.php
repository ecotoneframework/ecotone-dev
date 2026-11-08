<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * @internal
 */
final class MessagingInfrastructureTest extends TestCase
{
    public function test_a_distributed_send_from_the_booted_app_stays_in_memory_and_never_reaches_a_broker(): void
    {
        self::assertSame(0, Artisan::call('demo:ping', ['--waitSeconds' => 0]));
    }
}
