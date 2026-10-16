<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\Calendar\Meeting;

/**
 * @internal
 */
final class DbalConnectionRequirementTest extends TestCase
{
    public function test_throws_configuration_exception_when_dbal_connection_factory_is_not_configured(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Dbal module requires 'Ecotone\Api\Dbal\DbalConnectionFactory' to be configured");

        EcotoneLite::bootstrap(
            classesToResolve: [Meeting::class],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: [],
            environment: 'prod',
        );
    }

    public function test_does_not_throw_when_dbal_connection_factory_is_configured(): void
    {
        $connectionFactory = DbalMessagingTestCase::prepareConnection();

        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [Meeting::class],
            containerOrAvailableServices: [DbalConnectionFactory::class => $connectionFactory],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: [],
            environment: 'prod',
        );

        self::assertNotNull($ecotone);
    }

    public function test_does_not_throw_when_dbal_connection_factory_is_configured_using_container(): void
    {
        $connectionFactory = DbalMessagingTestCase::prepareConnection();

        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [Meeting::class],
            containerOrAvailableServices: InMemoryPSRContainer::createFromAssociativeArray([DbalConnectionFactory::class => $connectionFactory]),
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: [],
            environment: 'prod',
        );

        self::assertNotNull($ecotone);
    }
}
