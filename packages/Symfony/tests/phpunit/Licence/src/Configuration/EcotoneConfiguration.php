<?php

declare(strict_types=1);

namespace Symfony\App\Licence\Configuration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;

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
