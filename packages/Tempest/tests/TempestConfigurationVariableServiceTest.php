<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest;

use Ecotone\Tempest\TempestConfigurationVariableService;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class TempestConfigurationVariableServiceTest extends TestCase
{
    public function test_reads_env_variables(): void
    {
        putenv('ECOTONE_TEST_VAR=test-value');

        $service = new TempestConfigurationVariableService();

        $this->assertTrue($service->hasName('ECOTONE_TEST_VAR'));
        $this->assertSame('test-value', $service->getByName('ECOTONE_TEST_VAR'));
    }

    public function test_returns_false_for_missing_env_variable(): void
    {
        $service = new TempestConfigurationVariableService();

        $this->assertFalse($service->hasName('ECOTONE_NONEXISTENT_VAR_XYZ'));
        $this->assertNull($service->getByName('ECOTONE_NONEXISTENT_VAR_XYZ'));
    }
}
