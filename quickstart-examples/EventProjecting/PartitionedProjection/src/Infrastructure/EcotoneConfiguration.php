<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\Infrastructure;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\EventSourcing\Api\ExtensionObject\EventSourcingConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function asyncProjectionChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('async_projection');
    }
}

