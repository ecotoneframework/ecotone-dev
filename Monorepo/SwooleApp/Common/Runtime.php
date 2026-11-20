<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

final class Runtime
{
    public static function coroutineId(): int
    {
        foreach (['OpenSwoole\\Coroutine', 'Swoole\\Coroutine'] as $coroutineClass) {
            if (class_exists($coroutineClass, false)) {
                return $coroutineClass::getCid();
            }
        }

        return -1;
    }
}
