<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\EventsConverter;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\OrderListProjection;
use Test\Ecotone\EventSourcing\Projecting\App\Tooling\CommitOnUserInputInterceptor;
use Test\Ecotone\EventSourcing\Projecting\App\Tooling\WaitBeforeExecutingProjectionInterceptor;

if (! class_exists(ClassLoader::class, false)) {
    require_once getenv('COMPOSER_AUTOLOAD_FILE') ?: __DIR__ . '/../../../../../vendor/autoload.php';
}

$dbalFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone');

$services = [
    OrderListProjection::class => new OrderListProjection($dbalFactory->establishConnection()),
    DbalConnectionFactory::class => $dbalFactory,
    CommitOnUserInputInterceptor::class => new CommitOnUserInputInterceptor(),
    WaitBeforeExecutingProjectionInterceptor::class => new WaitBeforeExecutingProjectionInterceptor(),
    EventsConverter::class => new EventsConverter(),
];

return EcotoneLite::bootstrap(
    containerOrAvailableServices: $services,
    configuration: ServiceConfiguration::createWithDefaults()
        ->doNotLoadCatalog()
        ->withFailFast(false)
        ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        ->withDefaultErrorChannel('errorChannel')
        ->withNamespaces(['Test\\Ecotone\\EventSourcing\\Projecting\\App'])
        ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])),
);
