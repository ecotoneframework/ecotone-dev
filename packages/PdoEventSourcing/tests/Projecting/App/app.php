<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Test\LicenceTesting;
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
    extensionObjects: [
        DbalConfiguration::createWithDefaults(),
        ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel'),
    ],
    enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE],
    licenceKey: LicenceTesting::VALID_LICENCE,
    namespaces: ['Test\\Ecotone\\EventSourcing\\Projecting\\App'],
);
