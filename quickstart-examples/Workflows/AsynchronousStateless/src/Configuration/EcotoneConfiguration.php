<?php

declare(strict_types=1);

namespace App\Workflow\Configuration;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function messageChannel()
    {
        return DbalBackedMessageChannelBuilder::create('async_workflow');
    }
}