<?php

namespace Ecotone\Laravel;

use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * licence Apache-2.0
 */
class LaravelLogger extends AbstractLogger implements LoggerInterface
{
    public function log($level, $message, array $context = []): void
    {
        Log::log($level, (string) $message, $context);
    }
}
