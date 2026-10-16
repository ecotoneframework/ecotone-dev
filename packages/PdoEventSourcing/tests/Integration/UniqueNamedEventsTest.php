<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class UniqueNamedEventsTest extends TestCase
{
    public function test_event_names_should_be_unique(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            extensionObjects: [InMemoryRepositoryBuilder::createForAllEventSourcedAggregates()],
            enabledModules: [ModulePackageList::EVENT_SOURCING_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: ['Test\Ecotone\EventSourcing\Fixture\UniqueEventNames', ],
        )
            ->getFlowTestSupport()
        ;
    }
}
