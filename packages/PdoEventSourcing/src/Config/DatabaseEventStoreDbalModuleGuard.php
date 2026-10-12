<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class DatabaseEventStoreDbalModuleGuard
{
    public static function assertDbalModuleIsEnabled(EventSourcingConfiguration $eventSourcingConfiguration, ServiceConfiguration $serviceConfiguration): void
    {
        if ($eventSourcingConfiguration->isInMemory() || $serviceConfiguration->isModulePackageEnabled(ModulePackageList::DBAL_PACKAGE)) {
            return;
        }

        throw ConfigurationException::create(
            'Event sourcing is configured to store events in the database, but the DBAL module is not enabled. '
            . 'The event stream, projection state and tag tables belong to the DBAL module: without it nothing can create them '
            . '(ecotone:migration:database:setup and $ecotone->setupDatabaseTables() are the DBAL module\'s), '
            . 'so every append and every projection would fail on a missing table. '
            . 'Enable it by adding ModulePackageList::DBAL_PACKAGE to the module packages - '
            . 'ServiceConfiguration::withEnabledModulePackageNames([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ...]), '
            . "or 'dbal' in the modulePackages option of the Symfony bundle, config/ecotone.php in Laravel or EcotoneConfig in Tempest. "
            . 'To keep the events in memory instead, register EventSourcingConfiguration::createInMemory().'
        );
    }
}
