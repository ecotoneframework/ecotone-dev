<?php

declare(strict_types=1);

namespace Symfony\App\EnvPlaceholderEndpoint;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class PlaceOrderHandler
{
    #[Asynchronous('orders', asynchronousExecution: [new ErrorChannel('errorChannel.%env(ECOTONE_ERROR_CHANNEL)%')])]
    #[CommandHandler('order.place', endpointId: 'placeOrderEndpoint')]
    public function placeOrder(string $command): void
    {
    }
}
