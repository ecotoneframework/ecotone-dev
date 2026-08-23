<?php declare(strict_types=1);

namespace App\EventSourcing\Infrastructure;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\EventSourcing\Api\ExtensionObject\EventSourcingConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

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