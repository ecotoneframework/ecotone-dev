<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

use App\Domain\Command\RegisterCustomer;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use PHPUnit\Framework\Assert;
use Tempest\Core\Tempest;

require __DIR__ . '/vendor/autoload.php';

$container = Tempest::boot(__DIR__);

/** @var ConfiguredMessagingSystem $messagingSystem */
$messagingSystem = $container->get(ConfiguredMessagingSystem::class);
/** @var CommandBus $commandBus */
$commandBus = $container->get(CommandBus::class);
/** @var QueryBus $queryBus */
$queryBus = $container->get(QueryBus::class);

echo "== Tempest Multi-Tenant Quickstart - Message Bus ==\n\n";

echo "1) Create the customers table in each tenant database\n";
$multiTenant = $messagingSystem->getServiceFromContainer(MultiTenantConnectionFactory::class);
foreach (['tenant_a', 'tenant_b'] as $tenant) {
    $connection = $multiTenant->getConnection($tenant);
    $connection->executeStatement('DROP TABLE IF EXISTS customers');
    $connection->executeStatement('CREATE TABLE customers (id VARCHAR(36) PRIMARY KEY, name VARCHAR(255) NOT NULL)');
}
echo "   Table 'customers' ready in tenant_a (PostgreSQL) and tenant_b (MySQL)\n\n";

echo "2) Register customers, routing each write to its tenant via metadata['tenant']\n";
$commandBus->send(new RegisterCustomer('Alice'), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer('Bob'), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer('Carol'), metadata: ['tenant' => 'tenant_b']);
echo "   tenant_a <- Alice, Bob   tenant_b <- Carol\n\n";

echo "3) #[MultiTenantConnection] resolves to the active tenant's physical database\n";
$platformA = $queryBus->sendWithRouting('customer.platformForActiveTenant', metadata: ['tenant' => 'tenant_a']);
$platformB = $queryBus->sendWithRouting('customer.platformForActiveTenant', metadata: ['tenant' => 'tenant_b']);
Assert::assertStringContainsStringIgnoringCase('postgre', $platformA);
Assert::assertStringContainsStringIgnoringCase('mysql', $platformB);
echo "   tenant_a -> $platformA\n   tenant_b -> $platformB\n\n";

echo "4) Each tenant sees only its own customers (isolation)\n";
$tenantA = $queryBus->sendWithRouting('customer.listForActiveTenant', metadata: ['tenant' => 'tenant_a']);
$tenantB = $queryBus->sendWithRouting('customer.listForActiveTenant', metadata: ['tenant' => 'tenant_b']);

$namesA = array_column($tenantA, 'name');
$namesB = array_column($tenantB, 'name');

Assert::assertSame(['Alice', 'Bob'], $namesA);
Assert::assertSame(['Carol'], $namesB);
Assert::assertNotContains('Carol', $namesA);
Assert::assertNotContains('Alice', $namesB);
Assert::assertNotContains('Bob', $namesB);
echo "   tenant_a -> [" . implode(', ', $namesA) . "]\n   tenant_b -> [" . implode(', ', $namesB) . "]\n\n";

echo "== Example completed successfully ==\n";
