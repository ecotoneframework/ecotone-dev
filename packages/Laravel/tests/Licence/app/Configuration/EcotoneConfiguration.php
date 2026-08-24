<?php

declare(strict_types=1);

namespace App\Licence\Laravel\Configuration;

use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;

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
