<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Monorepo\Projecting\App\Ordering\OrderListProjection;
use Monorepo\Projecting\Tooling\CommitOnUserInputInterceptor;
use Monorepo\Projecting\Tooling\WaitBeforeExecutingProjectionInterceptor;

require_once __DIR__ . '/../../vendor/autoload.php';

$dbalFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone');

$services = [
    OrderListProjection::class => new OrderListProjection($dbalFactory->establishConnection()),
    DbalConnectionFactory::class => $dbalFactory,
    CommitOnUserInputInterceptor::class => new CommitOnUserInputInterceptor(),
    WaitBeforeExecutingProjectionInterceptor::class => new WaitBeforeExecutingProjectionInterceptor(),
];

return EcotoneLite::bootstrap(
    containerOrAvailableServices: $services,
    configuration: ServiceConfiguration::createWithDefaults()
        ->doNotLoadCatalog()
        ->withFailFast(false)
        ->withDefaultErrorChannel('errorChannel')
        ->withNamespaces(['Monorepo\\Projecting\\'])
        ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])),

);