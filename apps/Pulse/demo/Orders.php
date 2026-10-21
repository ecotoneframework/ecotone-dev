<?php

declare(strict_types=1);

namespace Demo;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Pulse\PulseConfiguration;
use RuntimeException;

final class Orders
{
    public const SHARED_CONNECTION = 'pulse_shared';

    #[ServiceContext]
    public function serviceName(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()->withServiceName('orders')->withDefaultErrorChannel('dbal_dead_letter');
    }

    #[ServiceContext]
    public function pulse(): PulseConfiguration
    {
        return PulseConfiguration::createWithDefaults(pulseServiceName: 'ecotone_pulse');
    }

    #[ServiceContext]
    public function distribution(): DistributedServiceMap
    {
        return DistributedServiceMap::initialize()->withCommandMapping('billing', 'billing_channel');
    }

    #[ServiceContext]
    public function channels(): array
    {
        return array_map(
            static fn (string $name) => DbalBackedMessageChannelBuilder::create($name, self::SHARED_CONNECTION)->withReceiveTimeout(500),
            ['orders', 'ecotone_pulse', 'orders_async', 'billing_channel']
        );
    }

    #[CommandHandler('order.place', endpointId: 'order.place.handler')]
    public function place(array $order, CommandBus $commandBus, DistributedBus $distributedBus): void
    {
        $commandBus->sendWithRouting('order.reserve_stock', $order);
        $distributedBus->sendCommand('billing', 'invoice.create', json_encode($order));
    }

    #[Asynchronous('orders_async')]
    #[CommandHandler('order.reserve_stock', endpointId: 'order.reserve_stock.handler')]
    public function reserve(array $order): void
    {
        usleep(random_int(20, 120) * 1000);
        if (($order['sku'] ?? '') === 'OUT-OF-STOCK') {
            throw new RuntimeException('SKU OUT-OF-STOCK has no free stock for order ' . ($order['orderId'] ?? '?'));
        }
    }
}
