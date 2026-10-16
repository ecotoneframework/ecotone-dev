<?php

require __DIR__ . "/vendor/autoload.php";

use App\Domain\Command\AddMoneyToWallet;
use App\Domain\Command\SubtractMoneyFromWallet;
use App\ReadModel\NotificationService;
use App\ReadModel\WalletBalance\WalletBalanceProjection;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;

$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new NotificationService(),
        new WalletBalanceProjection(),
        DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ],
    pathToRootCatalog: __DIR__,
    namespaces: ['App'],
);
$messagingSystem->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

$walletId = Uuid::uuid4()->toString();

echo "Adding 100 to balance\n";
$commandBus->send(new AddMoneyToWallet($walletId, 100));
echo "Subtracting 10 from balance\n";
$commandBus->send(new SubtractMoneyFromWallet($walletId, 10));
echo "Adding 50 to balance\n";
$commandBus->send(new AddMoneyToWallet($walletId, 50));