<?php

use App\MultiTenant\ProcessImage;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::bootstrap(
    objectsToRegister: [
        'logger' => new EchoLogger(),
        'tenant_a_factory' => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
        'tenant_b_factory' => new DbalConnectionFactory(getenv('SECONDARY_DATABASE_DSN') ? getenv('SECONDARY_DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ],
    configurationVariables: [
        'tenant_connection_factories' => ['tenant_a' => 'tenant_a_factory', 'tenant_b' => 'tenant_b_factory'],
    ],
    serviceConfiguration: ServiceConfiguration::createWithDefaults()
                                ->withExtensionObjects([
                                    MultiTenantConfiguration::create(
                                        DbalConnectionFactory::class,
                                        'tenant',
                                        ['tenant_a' => 'tenant_a_factory', 'tenant_b' => 'tenant_b_factory'],
                                    )
                                ]),
    pathToRootCatalog: __DIR__
);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";
TestCase::assertSame([], $queryBus->sendWithRouting('getProcessedImages'));

$commandBus->send(new ProcessImage('1', "Picture of Milk"), ['tenant' => 'tenant_a']);
$messagingSystem->run('image_processing');
TestCase::assertSame(['1'], $queryBus->sendWithRouting('getProcessedImages'));

$commandBus->send(new ProcessImage('2', 'Picture of Chocolate'), ['tenant' => 'tenant_b']);
$messagingSystem->run('image_processing');
TestCase::assertSame(['1', '2'], $queryBus->sendWithRouting('getProcessedImages'));

$commandBus->send(new ProcessImage('3', 'Picture of town'), ['tenant' => 'tenant_a']);
$messagingSystem->run('image_processing');
TestCase::assertSame(['1', '2', '3'], $queryBus->sendWithRouting('getProcessedImages'));