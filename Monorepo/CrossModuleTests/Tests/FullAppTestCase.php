<?php

namespace Monorepo\CrossModuleTests\Tests;

use Monorepo\Benchmark\FullAppBenchmarkCase;

abstract class FullAppTestCase extends FullAppBenchmarkCase
{
    public function test_symfony_prod()
    {
        self::clearSymfonyCache();
        $this->bench_symfony_prod();
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_symfony_dev()
    {
        self::clearSymfonyCache();
        \ini_set("error_log", "/dev/null");
        $this->bench_symfony_dev();
    }

    public function test_laravel_prod(): void
    {
        self::clearLaravelCache();
        $this->bench_laravel_prod();
    }

    public function test_laravel_dev(): void
    {
        self::clearLaravelCache();
        $this->bench_laravel_dev();
    }

    public function test_lite_application_prod()
    {
        self::clearLiteApplicationCache();
        $this->bench_lite_application_prod();
    }

    public function test_lite_application_dev()
    {
        self::clearLiteApplicationCache();
        $this->bench_lite_application_dev();
    }

    public function test_lite_prod()
    {
        self::clearLiteCache();
        $this->bench_lite_prod();
    }

    public function test_lite_dev()
    {
        self::clearLiteCache();
        $this->bench_lite_dev();
    }
}