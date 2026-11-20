<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * SQLite lets one connection write at a time and makes the others wait inside the database call, which stops every
 * coroutine of the process, the one holding the write transaction included. So the coroutines of one process take
 * turns here before they open a write transaction, and wait for their turn without stopping each other.
 */
final class WriterTurnBetweenCoroutines
{
    private const SECONDS_TO_WAIT_FOR_THE_TURN = 5.0;
    private const COROUTINE = 'OpenSwoole\\Coroutine';
    private const CHANNEL = 'OpenSwoole\\Coroutine\\Channel';

    private static ?object $turn = null;
    private static int $coroutineHoldingTheTurn = 0;

    public static function take(): void
    {
        $coroutine = self::currentCoroutine();
        if ($coroutine === 0) {
            return;
        }

        self::$turn ??= new (self::CHANNEL)(1);
        if (self::$turn->push($coroutine, self::SECONDS_TO_WAIT_FOR_THE_TURN)) {
            self::$coroutineHoldingTheTurn = $coroutine;
        }
    }

    public static function giveBack(): void
    {
        $coroutine = self::currentCoroutine();
        if ($coroutine === 0 || self::$coroutineHoldingTheTurn !== $coroutine) {
            return;
        }

        self::$coroutineHoldingTheTurn = 0;
        self::$turn?->pop(0.001);
    }

    private static function currentCoroutine(): int
    {
        return class_exists(self::COROUTINE, false) ? max(0, (int) (self::COROUTINE)::getCid()) : 0;
    }
}
