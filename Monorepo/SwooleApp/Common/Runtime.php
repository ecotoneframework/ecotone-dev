<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

final class Runtime
{
    public static function coroutineId(): int
    {
        return class_exists(\OpenSwoole\Coroutine::class, false) ? \OpenSwoole\Coroutine::getCid() : -1;
    }
}
