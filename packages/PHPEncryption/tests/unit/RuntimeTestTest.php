<?php

namespace Test\Ecotone\PHPEncryption;

use Ecotone\PHPEncryption\RuntimeTests;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @internal
 */
class RuntimeTestTest extends TestCase
{
    public function test_runtime_test()
    {
        try {
            RuntimeTests::runtimeTest();
        } finally {
            self::assertTrue(true); // do not fail the test
        }
    }
}
