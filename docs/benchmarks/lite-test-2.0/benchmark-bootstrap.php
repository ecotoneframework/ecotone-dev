<?php

declare(strict_types=1);

/**
 * licence Apache-2.0
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/gc-probe.php';
if (getenv('LITE_RESOLVER_PROBE')) {
    require getenv('LITE_RESOLVER_PROBE');
}
