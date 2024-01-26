<?php

use App\MultiTenant\Application\RegisterPerson;
use App\MultiTenant\ProcessImage;
use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\ManagerRegistryEmulator;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;

require __DIR__ . "/../boostrap.php";

$messagingSystem = bootstrapEcotone(__DIR__);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$commandBus->send(new RegisterPerson(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterPerson(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterPerson(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);