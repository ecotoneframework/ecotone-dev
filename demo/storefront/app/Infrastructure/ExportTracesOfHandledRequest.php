<?php

declare(strict_types=1);

namespace App\Infrastructure;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

final class ExportTracesOfHandledRequest
{
    public function handle(object $requestTerminated): void
    {
        $tracerProvider = Globals::tracerProvider();
        if ($tracerProvider instanceof TracerProviderInterface) {
            $tracerProvider->forceFlush();
        }
    }
}
