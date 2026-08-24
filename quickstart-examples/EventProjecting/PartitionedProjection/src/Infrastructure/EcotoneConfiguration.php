<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\Infrastructure;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ServiceContext;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function asyncProjectionChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('async_projection');
    }
}

