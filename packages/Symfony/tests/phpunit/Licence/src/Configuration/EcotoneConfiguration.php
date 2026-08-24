<?php

declare(strict_types=1);

namespace Symfony\App\Licence\Configuration;

use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;

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
                        'queue',
                    ]
                ),
                SimpleMessageChannelBuilder::createQueueChannel('queue'),
            ];
    }
}
