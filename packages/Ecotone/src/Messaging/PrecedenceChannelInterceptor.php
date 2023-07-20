<?php

declare(strict_types=1);

namespace Ecotone\Messaging;

interface PrecedenceChannelInterceptor
{
    public const DEFAULT_PRECEDENCE = 0;

    public const MESSAGE_SERIALIZATION = 2000;

    public const COLLECTOR_PRECEDENCE = self::MESSAGE_SERIALIZATION + 1;
}
