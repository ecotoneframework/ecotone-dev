<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\Tempest\EcotoneConfig;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ParameterExpressionTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\ExpressionLanguage\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
        );
    }

    public function test_parameter_function_with_hardcoded_env_value_in_expression(): void
    {
        putenv('ECOTONE_MULTIPLIER=10');

        $commandBus = $this->container->get(CommandBus::class);
        $queryBus = $this->container->get(QueryBus::class);

        $commandBus->sendWithRouting('calculator.multiply', ['value' => 5]);

        $this->assertSame(50, $queryBus->sendWithRouting('calculator.getResult'));

        putenv('ECOTONE_MULTIPLIER');
    }

    public function test_parameter_function_with_env_variable_in_expression(): void
    {
        putenv('ECOTONE_ENV_MULTIPLIER=7');

        $commandBus = $this->container->get(CommandBus::class);
        $queryBus = $this->container->get(QueryBus::class);

        $commandBus->sendWithRouting('calculator.multiplyWithEnv', ['value' => 3]);

        $this->assertSame(21, $queryBus->sendWithRouting('calculator.getEnvResult'));

        putenv('ECOTONE_ENV_MULTIPLIER');
    }
}
