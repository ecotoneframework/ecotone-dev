<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

/**
 * @deprecated inject Clock interface instead of using GlobalClock
 */
class GlobalClock
{
    private static ?Clock $clock = null;

    public static function set(Clock $clock): void
    {
        self::$clock = $clock;
    }

    public static function get(): Clock
    {
        if (self::$clock === null) {
            self::$clock = new NativeClock();
        }

        return self::$clock;
    }
}