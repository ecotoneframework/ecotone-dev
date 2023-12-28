<?php

declare(strict_types=1);

namespace App\BusinessInterface;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

final readonly class MessagingConfiguration
{
    #[ServiceContext]
    public function asyncChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create("async");
    }
}