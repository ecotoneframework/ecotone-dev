<?php

declare(strict_types=1);

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;

require __DIR__ . '/../vendor/autoload.php';

$service = EcotoneLite::bootstrapFlowTesting(
    containerOrAvailableServices: [DbalConnectionFactory::class => new DbalConnectionFactory(getenv('PULSE_TRANSPORT_DSN'))],
    extensionObjects: [
        DbalBackedMessageChannelBuilder::create('orders_channel')->withReceiveTimeout(1),
        DbalBackedMessageChannelBuilder::create('ecotone_pulse'),
        DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'ecotone_pulse'),
        ServiceConfiguration::createWithDefaults()->withServiceName('orders'),
        PulseConfiguration::createWithDefaults('ecotone_pulse'),
    ],
    enabledModules: [ModulePackageList::DBAL_PACKAGE],
    licenceKey: getenv('ECOTONE_LICENCE_KEY'),
);

$deadline = time() + (int) (getenv('SMOKE_SECONDS') ?: 120);
while (time() < $deadline) {
    $service->run('orders_channel', ExecutionPollingMetadata::createWithTestingSetup());
    usleep(200_000);
}
