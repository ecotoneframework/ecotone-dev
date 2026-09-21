<?php

declare(strict_types=1);

namespace LitePerformanceProbe {
    /**
     * licence Apache-2.0
     */
    final class Collections
    {
        public static array $calls = [];
        public static array $nanoseconds = [];
        public static array $collected = [];

        public static function collect(string $site): int
        {
            self::$calls[$site] = (self::$calls[$site] ?? 0) + 1;
            $policy = getenv($site === 'bootstrap' ? 'LITE_GC_POLICY' : 'ASYNC_GC_POLICY') ?: 'always';
            if ($policy === 'never' || ($policy === 'every32' && self::$calls[$site] % 32 !== 0) || ($policy === 'roots1000' && gc_status()['roots'] < 1000)) {
                return 0;
            }
            $start = hrtime(true);
            $collected = \gc_collect_cycles();
            self::$nanoseconds[$site] = (self::$nanoseconds[$site] ?? 0) + hrtime(true) - $start;
            self::$collected[$site] = (self::$collected[$site] ?? 0) + $collected;
            return $collected;
        }
    }

    register_shutdown_function(static function (): void {
        $result = [
            'bootstrap_policy' => getenv('LITE_GC_POLICY') ?: 'always',
            'async_policy' => getenv('ASYNC_GC_POLICY') ?: 'always',
            'calls' => Collections::$calls,
            'gc_nanoseconds' => Collections::$nanoseconds,
            'collected' => Collections::$collected,
            'peak_bytes' => memory_get_peak_usage(false),
            'peak_allocated_bytes' => memory_get_peak_usage(true),
            'gc_status' => gc_status(),
        ];
        file_put_contents(getenv('GC_REPORT_PATH') ?: '/tmp/lite-gc-report.jsonl', json_encode($result, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    });
}

namespace Ecotone\Lite {
    function gc_collect_cycles(): int
    {
        return \LitePerformanceProbe\Collections::collect('bootstrap');
    }
}

namespace Ecotone\Messaging\Endpoint\PollingConsumer {
    function gc_collect_cycles(): int
    {
        return \LitePerformanceProbe\Collections::collect('async');
    }
}
