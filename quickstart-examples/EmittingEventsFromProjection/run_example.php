<?php

require __DIR__ . "/vendor/autoload.php";

use App\Domain\Command\AddMoneyToWallet;
use App\Domain\Command\SubtractMoneyFromWallet;
use App\ReadModel\NotificationService;
use Ecotone\Lite\EcotoneLiteApplication;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;

$messagingSystem = EcotoneLiteApplication::boostrap([DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone')]);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

$walletId = Uuid::uuid4()->toString();

echo "Adding 100 to balance\n";
$commandBus->send(new AddMoneyToWallet($walletId, 100));
echo "Subtracting 10 from balance\n";
$commandBus->send(new SubtractMoneyFromWallet($walletId, 10));
echo "Adding 50 to balance\n";
$commandBus->send(new AddMoneyToWallet($walletId, 50));