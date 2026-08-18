<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest;

use Ecotone\Tempest\TempestConfigurationVariableService;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Test\Ecotone\Tempest\Fixture\Config\OrdersMultiTenancyConfig;

/**
 * licence Apache-2.0
 * @internal
 */
final class TempestConfigurationVariableServiceTest extends TestCase
{
    public function test_reads_env_variables(): void
    {
        putenv('ECOTONE_TEST_VAR=test-value');

        $service = new TempestConfigurationVariableService(new GenericContainer());

        $this->assertTrue($service->hasName('ECOTONE_TEST_VAR'));
        $this->assertSame('test-value', $service->getByName('ECOTONE_TEST_VAR'));
    }

    public function test_returns_false_for_missing_env_variable(): void
    {
        $service = new TempestConfigurationVariableService(new GenericContainer());

        $this->assertFalse($service->hasName('ECOTONE_NONEXISTENT_VAR_XYZ'));
        $this->assertNull($service->getByName('ECOTONE_NONEXISTENT_VAR_XYZ'));
    }

    public function test_reads_property_from_a_config_class_registered_in_the_container(): void
    {
        $container = new GenericContainer();
        $container->singleton(OrdersMultiTenancyConfig::class, new OrdersMultiTenancyConfig('dynamicOrdersTopic'));

        $service = new TempestConfigurationVariableService($container);

        $this->assertTrue($service->hasName(OrdersMultiTenancyConfig::class . '::topicReferenceName'));
        $this->assertSame('dynamicOrdersTopic', $service->getByName(OrdersMultiTenancyConfig::class . '::topicReferenceName'));
    }

    public function test_returns_false_for_a_config_class_property_that_does_not_exist(): void
    {
        $service = new TempestConfigurationVariableService(new GenericContainer());

        $this->assertFalse($service->hasName(OrdersMultiTenancyConfig::class . '::missingProperty'));
    }
}
