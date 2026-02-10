<?php

namespace Test\Ecotone\DataProtection\Unit\Encryption;

use Ecotone\DataProtection\Encryption\RuntimeTests;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class RuntimeTestTest extends TestCase
{
    public function test_runtime_test(): void
    {
        try {
            RuntimeTests::runtimeTest();
        } finally {
            self::assertTrue(true); // do not fail the test
        }
    }
}
