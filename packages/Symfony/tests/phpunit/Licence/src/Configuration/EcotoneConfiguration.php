<?php

declare(strict_types=1);

namespace Symfony\App\Licence\Configuration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;

/**
 * licence Enterprise
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseChannel(): array
    {
        return
            [
                DynamicMessageChannelBuilder::createRoundRobin(
                'notifications',
                [
                    'queue'
                ]
            ),
                SimpleMessageChannelBuilder::createQueueChannel('queue')
        ];
    }
}
