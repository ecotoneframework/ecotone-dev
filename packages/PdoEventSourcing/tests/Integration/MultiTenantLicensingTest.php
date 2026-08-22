<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\LicensingException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

final class MultiTenantLicensingTest extends EventSourcingMessagingTestCase
{
    public function test_throws_when_multi_tenant_configuration_is_used_without_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Multi-tenancy');
        $this->expectExceptionMessage('https://docs.ecotone.tech/enterprise');

        EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE])
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\Ticket'])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ],
                    ),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
        );
    }
}
