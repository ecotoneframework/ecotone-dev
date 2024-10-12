<?php

declare(strict_types=1);

namespace App\Licence\Laravel\Configuration;

use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Laravel\Config\LaravelConnectionReference;
use Ecotone\Laravel\Queue\LaravelQueueMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

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
                    'memory'
                ]
            ),
            SimpleMessageChannelBuilder::createQueueChannel('memory')
        ];
    }
}
