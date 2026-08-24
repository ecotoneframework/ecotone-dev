<?php

declare(strict_types=1);

namespace Symfony\App\EnvPlaceholderEndpoint\Configuration;

use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function ordersChannel(): array
    {
        return [
            SimpleMessageChannelBuilder::createQueueChannel('orders'),
        ];
    }
}
