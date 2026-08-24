<?php declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\Common\Infrastructure;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ServiceContext;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function turnOffTransactions(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults();
    }

    #[ServiceContext]
    public function es(): EventSourcingConfiguration
    {
        return EventSourcingConfiguration::createWithDefaults()
            ->withInitializeEventStoreOnStart(true);
    }
}