<?php

namespace Monorepo\Benchmark;

use PHPUnit\Framework\TestCase;

/**
 * Base for real PHPUnit test cases (run via `vendor/bin/phpunit`) that need the shared
 * app-booting/cache-clearing/benchmark helpers in FullAppBenchmarkCaseTrait. PHPUnit
 * constructs its TestCase subclasses itself (passing the test method name into
 * TestCase::__construct(), which PHPUnit 12 made final), so extending TestCase here is safe.
 *
 * Pure PHPBench subjects (executed via `new $class()` with no constructor arguments,
 * see phpbench's remote.template) must NOT extend this class — TestCase::__construct()
 * requires a mandatory argument PHPBench never supplies. They should extend
 * PHPUnit\Framework\Assert directly and `use FullAppBenchmarkCaseTrait;` instead.
 */
abstract class FullAppBenchmarkCase extends TestCase
{
    use FullAppBenchmarkCaseTrait;
}
