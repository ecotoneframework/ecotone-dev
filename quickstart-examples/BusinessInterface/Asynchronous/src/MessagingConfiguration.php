<?php

declare(strict_types=1);

namespace App\BusinessInterface;

use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;

final readonly class MessagingConfiguration
{
    #[ServiceContext]
    public function asyncChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create("async");
    }
}