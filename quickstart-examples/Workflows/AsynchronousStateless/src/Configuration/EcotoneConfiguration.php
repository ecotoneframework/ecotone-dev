<?php

declare(strict_types=1);

namespace App\Workflow\Configuration;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function messageChannel()
    {
        return DbalBackedMessageChannelBuilder::create('async_workflow');
    }
}