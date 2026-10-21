<?php

declare(strict_types=1);

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Orders.php';
require __DIR__ . '/Billing.php';

function demoDsn(?string $service = null): string
{
    $dsn = getenv('PULSE_TRANSPORT_DSN') ?: 'pgsql://ecotone:secret@database:5432/pulse_demo';

    return $service === null ? $dsn : preg_replace('#/pulse_demo$#', '/pulse_demo_' . $service, $dsn);
}

function bootDemoService(string $service): ConfiguredMessagingSystem
{
    return EcotoneLite::bootstrap(
        classesToResolve: [$service === 'orders' ? Demo\Orders::class : Demo\Billing::class],
        containerOrAvailableServices: [
            DbalConnectionFactory::class => new DbalConnectionFactory(demoDsn($service)),
            Demo\Orders::SHARED_CONNECTION => new DbalConnectionFactory(demoDsn()),
            Demo\Orders::class => new Demo\Orders(),
            Demo\Billing::class => new Demo\Billing(),
        ],
        extensionObjects: [
            DbalConfiguration::createWithDefaults()->withDeadLetter(true),
            FlowTrackingConfiguration::createWithDefaults(),
        ],
        enabledModules: [ModulePackageList::DBAL_PACKAGE],
        licenceKey: getenv('ECOTONE_LICENCE_KEY'),
        pathToRootCatalog: realpath(__DIR__ . '/..'),
        cacheDirectoryPath: sys_get_temp_dir() . '/pulse-demo-' . $service,
    );
}
