<?php

namespace Monorepo\CrossModuleTests\Tests;

use Monorepo\Benchmark\FullAppBenchmarkCase;

abstract class FullAppTestCase extends FullAppBenchmarkCase
{
    public function test_symfony_prod()
    {
        $this->bench_symfony_prod();
    }

    public function test_symfony_dev()
    {
        $this->bench_laravel_dev();
    }

    public function test_laravel_prod(): void
    {
        $this->bench_laravel_prod();
    }

    public function test_laravel_dev(): void
    {
        $this->bench_laravel_dev();
    }

    public function test_lite_application_prod()
    {
        $this->bench_lite_application_prod();
    }

    public function test_lite_application_dev()
    {
        $this->bench_lite_application_dev();
    }

    public function test_lite_prod()
    {
        $this->bench_lite_prod();
    }

    public function test_lite_dev()
    {
        $this->bench_lite_dev();
    }
}