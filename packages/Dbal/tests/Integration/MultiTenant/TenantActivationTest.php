<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Dbal\Attribute\OnTenantActivation;
use Ecotone\Api\Dbal\Attribute\OnTenantDeactivation;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class TenantActivationTest extends DbalMessagingTestCase
{
    public function test_a_command_sent_with_a_tenant_header_runs_between_that_tenant_activation_and_deactivation(): void
    {
        [$ecotone, $tenantSwitch] = $this->bootstrapEcotone();

        $ecotone->sendCommandWithRouting('tenant.report', metadata: ['tenant' => 'tenant_a']);

        $this->assertSame(['activated tenant_a', 'handled tenant_a', 'deactivated tenant_a'], $tenantSwitch->steps);
    }

    public function test_a_console_command_run_with_a_tenant_header_runs_between_that_tenant_activation_and_deactivation(): void
    {
        [$ecotone, $tenantSwitch] = $this->bootstrapEcotone();

        $ecotone->runConsoleCommand('tenant:report', ['header' => ['tenant:tenant_b']]);

        $this->assertSame(['activated tenant_b', 'handled tenant_b', 'deactivated tenant_b'], $tenantSwitch->steps);
    }

    /**
     * @return array{FlowTestSupport, object}
     */
    private function bootstrapEcotone(): array
    {
        $tenantSwitch = new class () {
            public array $steps = [];

            #[OnTenantActivation]
            public function activated(string|ConnectionReference $tenantConnection, #[Header('tenant')] string $tenant): void
            {
                $this->steps[] = "activated {$tenant}";
            }

            #[OnTenantDeactivation]
            public function deactivated(string|ConnectionReference $tenantConnection, #[Header('tenant')] string $tenant): void
            {
                $this->steps[] = "deactivated {$tenant}";
            }

            #[CommandHandler('tenant.report')]
            public function handle(#[Header('tenant')] string $tenant): void
            {
                $this->steps[] = "handled {$tenant}";
            }

            #[ConsoleCommand('tenant:report')]
            public function report(#[Header('tenant')] string $tenant): void
            {
                $this->steps[] = "handled {$tenant}";
            }
        };

        $ecotone = $this->bootstrapFlowTesting(
            [$tenantSwitch::class],
            [
                $tenantSwitch,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    MultiTenantConfiguration::create(
                        tenantHeaderName: 'tenant',
                        tenantToConnectionMapping: [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ],
                    ),
                ]),
        );

        return [$ecotone, $tenantSwitch];
    }
}
