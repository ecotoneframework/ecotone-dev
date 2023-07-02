<?php

declare(strict_types=1);

namespace Ecotone\Messaging;

interface PrecedenceChannelInterceptor
{
    public const DEFAULT_PRECEDENCE = 0;

    public const COLLECTOR_PRECEDENCE = 2000;
}