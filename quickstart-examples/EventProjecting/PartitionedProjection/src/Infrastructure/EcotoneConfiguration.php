<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\Infrastructure;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function asyncProjectionChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('async_projection');
    }
}

