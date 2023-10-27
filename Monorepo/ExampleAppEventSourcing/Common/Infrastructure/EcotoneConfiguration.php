<?php declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\Common\Infrastructure;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function turnOffTransactions(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults();
    }
}