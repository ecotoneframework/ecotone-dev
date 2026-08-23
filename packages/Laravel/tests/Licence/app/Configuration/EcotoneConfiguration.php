<?php

declare(strict_types=1);

namespace App\Licence\Laravel\Configuration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function multiTenantConfiguration(): array
    {
        return [
            DynamicMessageChannelBuilder::createRoundRobin(
                'asynchronous',
                [
                    'memory',
                ]
            ),
            SimpleMessageChannelBuilder::createQueueChannel('memory'),
        ];
    }
}
