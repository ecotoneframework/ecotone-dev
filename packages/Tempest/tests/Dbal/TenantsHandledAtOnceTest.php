<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Dbal;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tempest\TempestConnectionReference;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use Fiber;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\EventBus\EventBus;
use Tempest\EventBus\EventBusConfig;
use Tempest\EventBus\GenericEventBus;
use Test\Ecotone\Tempest\TempestDatabaseConfigFactory;

/**
 * licence Apache-2.0
 * @internal
 */
final class TenantsHandledAtOnceTest extends TestCase
{
    protected function tearDown(): void
    {
        GenericContainer::setInstance(null);
    }

    public function test_tenant_activated_while_a_suspended_execution_has_another_tenant_active_is_refused(): void
    {
        $container = new GenericContainer();
        GenericContainer::setInstance($container);
        $container->singleton(EventBus::class, new GenericEventBus($container, new EventBusConfig()));
        $container->singleton(DatabaseConfig::class, TempestDatabaseConfigFactory::primary());
        $container->singleton(DatabaseConfig::class, TempestDatabaseConfigFactory::primary('tenant_a'), tag: 'tenant_a');
        $container->singleton(DatabaseConfig::class, TempestDatabaseConfigFactory::secondary('tenant_b'), tag: 'tenant_b');
        $customers = new class () {
            private array $registered = [];

            #[CommandHandler('customer.register_after_being_suspended')]
            public function registerAfterBeingSuspended(string $name): void
            {
                Fiber::suspend();
                $this->registered[] = $name;
            }

            #[CommandHandler('customer.register')]
            public function register(string $name): void
            {
                $this->registered[] = $name;
            }

            #[QueryHandler('customer.registered')]
            public function registered(): array
            {
                return $this->registered;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$customers::class],
            containerOrAvailableServices: [$customers],
            extensionObjects: [MultiTenantConfiguration::create('tenant', ['tenant_a' => TempestConnectionReference::create('tenant_a'), 'tenant_b' => TempestConnectionReference::create('tenant_b')])],
            enabledModules: [ModulePackageList::TEMPEST_PACKAGE, ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $suspended = new Fiber(static fn () => $ecotone->sendCommandWithRouting('customer.register_after_being_suspended', 'John Doe', metadata: ['tenant' => 'tenant_a']));
        $suspended->start();

        try {
            $ecotone->sendCommandWithRouting('customer.register', 'Jane Doe', metadata: ['tenant' => 'tenant_b']);
            $this->fail();
        } catch (ConfigurationException $exception) {
            $this->assertSame(
                'A tenant connection is activated while another message, handled at once in this process on a coroutine or a fiber, has its tenant active. '
                . "Tempest's default database connection is one for the whole process, so the models and queries of both messages would go to the tenant activated last. "
                . 'Handle one message at a time: run consumers without the `coroutines` option of ecotone:run and PollingMetadata::withCoroutines(), set `enable_coroutine` to false on a Swoole or OpenSwoole server, and do not suspend a fiber while it handles a message.',
                $exception->getMessage(),
            );
        } finally {
            $suspended->resume();
        }

        $this->assertSame(['John Doe'], $ecotone->sendQueryWithRouting('customer.registered', metadata: ['tenant' => 'tenant_a']));
    }
}
