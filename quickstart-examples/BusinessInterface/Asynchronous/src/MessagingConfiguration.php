<?php

declare(strict_types=1);

namespace App\BusinessInterface;

use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;

final readonly class MessagingConfiguration
{
    #[ServiceContext]
    public function asyncChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create("async");
    }
}