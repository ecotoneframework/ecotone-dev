<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\Attribute\CurrentConnectionObjectManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use Interop\Queue\ConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * licence Apache-2.0
 * @internal
 */
final class CurrentConnectionOfTenantTest extends DbalMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->setupUserTable($this->connectionForTenantA()->createContext()->getDbalConnection());
        $this->setupUserTable($this->connectionForTenantB()->createContext()->getDbalConnection());
    }

    public function test_connection_taken_without_a_name_is_the_one_of_the_active_tenant_when_tenants_are_registered_under_a_name_of_their_own(): void
    {
        $ecotone = $this->bootstrapPersonRegistrationOfTenantsRegisteredAs('tenant_connection');

        $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'of tenant a'], metadata: ['tenant' => 'tenant_a']);
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'of tenant b'], metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(['of tenant a'], $this->personsIn($this->connectionForTenantA()));
        $this->assertSame(['of tenant b'], $this->personsIn($this->connectionForTenantB()));
    }

    public function test_object_manager_taken_without_a_name_is_the_one_of_the_active_tenant_when_tenants_are_registered_under_a_name_of_their_own(): void
    {
        $ecotone = $this->bootstrapPersonRegistrationOfTenantsRegisteredAs('tenant_connection');

        $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 1, 'name' => 'of tenant a'], metadata: ['tenant' => 'tenant_a']);
        $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 2, 'name' => 'of tenant b'], metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(['of tenant a'], $this->personsIn($this->connectionForTenantA()));
        $this->assertSame(['of tenant b'], $this->personsIn($this->connectionForTenantB()));
    }

    public function test_second_multi_tenant_configuration_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'This application registers 2 MultiTenantConfiguration (as `orders_of_tenant`, `reports_of_tenant`), and Ecotone handles a message on the connection of one active tenant, so only one can be registered. '
            . 'Keep a single MultiTenantConfiguration and map every tenant to its connection in it. '
            . "A database that is not split by tenant is registered as a connection of its own and taken with #[CurrentConnection('its_reference_name')]."
        );

        $this->bootstrapFlowTesting(
            [],
            [
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            extensionObjects: [
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection'], 'orders_of_tenant'),
                MultiTenantConfiguration::create('tenant', ['tenant_b' => 'tenant_b_connection'], 'reports_of_tenant'),
                DbalConfiguration::createWithDefaults()->withDefaultConnectionReferenceNames(['orders_of_tenant'])->withDeduplication(false),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function personsIn(ConnectionFactory $connectionFactory): array
    {
        return $connectionFactory->createContext()->getDbalConnection()->fetchFirstColumn('SELECT name FROM persons ORDER BY person_id');
    }

    private function bootstrapPersonRegistrationOfTenantsRegisteredAs(string $referenceName): FlowTestSupport
    {
        $registration = new class () {
            #[CommandHandler('person.register')]
            public function register(array $command, #[CurrentConnection] Connection $connection): void
            {
                $connection->insert('persons', ['person_id' => $command['personId'], 'name' => $command['name']]);
            }

            #[CommandHandler('person.registerEntity')]
            public function registerEntity(array $command, #[CurrentConnectionObjectManager] ObjectManager $objectManager): void
            {
                $objectManager->persist(Person::register(new RegisterPerson($command['personId'], $command['name']), false));
                $objectManager->flush();
            }
        };

        return $this->bootstrapFlowTesting(
            [$registration::class],
            [
                $registration,
                'tenant_a_connection' => $this->connectionForTenantAWithManagerRegistry([__DIR__ . '/../../Fixture/ORM/Person']),
                'tenant_b_connection' => $this->connectionForTenantBWithManagerRegistry([__DIR__ . '/../../Fixture/ORM/Person']),
            ],
            extensionObjects: [
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'], $referenceName),
                DbalConfiguration::createWithDefaults()->withDefaultConnectionReferenceNames([$referenceName])->withDeduplication(false),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
