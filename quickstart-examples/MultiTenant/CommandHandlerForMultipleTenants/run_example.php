<?php

use App\MultiTenant\Application\RegisterPerson;
use App\MultiTenant\ProcessImage;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\ManagerRegistryEmulator;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;

require __DIR__ . "/vendor/autoload.php";

$messagingSystem = bootstrapEcotone();
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$commandBus->send(new RegisterPerson(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterPerson(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterPerson(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);

function bootstrapEcotone(): \Ecotone\Messaging\Config\ConfiguredMessagingSystem
{
    return EcotoneLiteApplication::bootstrap(
        objectsToRegister: [
            'logger' => new EchoLogger(),
            // Registering connection for tenants. Look src/Configuration/EcotoneConfiguration.php for usage based on tenant header
            'tenant_a_factory' => getConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
            'tenant_b_factory' => getConnectionFactory(getenv('SECONDARY_DATABASE_DSN') ? getenv('SECONDARY_DATABASE_DSN') : 'mysql://ecotone:secret@localhost:3306/ecotone'),
        ],
        pathToRootCatalog: __DIR__
    );
}

function getConnectionFactory(string $dsn): EcotoneManagerRegistryConnectionFactory
{
    /**
     * This is test class that emulates Doctrine ManagerRegistry. Follow link to configure:
     * @link https://docs.ecotone.tech/modules/dbal-support#configuration
     */
    return ManagerRegistryEmulator::fromDsnAndConfig($dsn, [__DIR__ . '/src/Domain']);
}