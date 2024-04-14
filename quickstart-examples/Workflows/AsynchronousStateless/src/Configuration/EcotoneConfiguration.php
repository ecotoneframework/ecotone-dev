<?php

declare(strict_types=1);

namespace App\Workflow\Configuration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function messageChannel()
    {
        return DbalBackedMessageChannelBuilder::create('async_workflow');
    }
}