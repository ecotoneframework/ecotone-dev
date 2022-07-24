<?php declare(strict_types=1);

namespace App\EventSourcing\Infrastructure;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

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