<?php declare(strict_types=1);

namespace App\EventSourcing\Infrastructure;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ServiceContext;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function getEventSourcingConfiguration(): EventSourcingConfiguration
    {
        return EventSourcingConfiguration::createInMemory();
    }

    #[ServiceContext]
    public function turnOffTransactions(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
                ->withTransactionOnCommandBus(false);
    }
}