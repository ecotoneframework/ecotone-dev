<?php

declare(strict_types=1);

namespace Demo;

use DomainException;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Pulse\PulseConfiguration;

final class Billing
{
    #[ServiceContext]
    public function serviceName(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()->withServiceName('billing')->withDefaultErrorChannel('dbal_dead_letter');
    }

    #[ServiceContext]
    public function pulse(): PulseConfiguration
    {
        return PulseConfiguration::createWithDefaults(pulseServiceName: 'ecotone_pulse');
    }

    #[ServiceContext]
    public function channels(): array
    {
        return array_map(
            static fn (string $name) => DbalBackedMessageChannelBuilder::create($name, Orders::SHARED_CONNECTION)->withReceiveTimeout(500),
            ['billing', 'ecotone_pulse', 'billing_channel', 'billing_async']
        );
    }

    #[Distributed]
    #[Asynchronous('billing_async')]
    #[CommandHandler('invoice.create', endpointId: 'invoice.create.handler')]
    public function create(string $payload): void
    {
        usleep(random_int(50, 250) * 1000);
        $order = json_decode($payload, true);
        if (($order['card'] ?? '') === 'DECLINED') {
            throw new DomainException('Payment provider declined the card for order ' . $order['orderId']);
        }
    }
}
