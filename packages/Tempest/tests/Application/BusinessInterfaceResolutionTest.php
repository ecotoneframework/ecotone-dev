<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\Fixture\Counter\CounterGateway;

/**
 * licence Apache-2.0
 * @internal
 */
final class BusinessInterfaceResolutionTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\Counter\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
        );
    }

    public function test_custom_business_interface_resolves_as_first_ecotone_touch(): void
    {
        $counterGateway = $this->container->get(CounterGateway::class);

        $this->assertInstanceOf(CounterGateway::class, $counterGateway);
    }

    public function test_custom_business_interface_forwards_to_handler_when_resolved_first(): void
    {
        $counterGateway = $this->container->get(CounterGateway::class);

        $commandBus = $this->container->get(\Ecotone\Modelling\CommandBus::class);
        $commandBus->sendWithRouting('counter.increment');

        $this->assertSame(1, $counterGateway->get());
    }
}
