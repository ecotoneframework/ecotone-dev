<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\HookTenantSwitchSubscription;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use PHPUnit\Framework\Assert;

require __DIR__ . "/vendor/autoload.php";
require __DIR__ . '/../boostrap.php';

$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new HookTenantSwitchSubscription(),
        'tenant_a_connection' => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
        'tenant_b_connection' => new DbalConnectionFactory(getenv('SECONDARY_DATABASE_DSN') ? getenv('SECONDARY_DATABASE_DSN') : 'mysql://ecotone:secret@localhost:3306/ecotone'),
        'logger' => new EchoLogger()
    ],
    pathToRootCatalog: __DIR__,
    licenceKey: getenv('ECOTONE_LICENSE_KEY') ?: null,
    namespaces: ['App'],
);
runMigrationForTenants($messagingSystem);

$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$commandBus->send(new RegisterCustomer(1, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_b']);