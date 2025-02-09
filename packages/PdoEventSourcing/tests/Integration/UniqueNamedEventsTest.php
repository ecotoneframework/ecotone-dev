<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
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

        EcotoneLite::bootstrapForTesting(
            [Ticket::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllEventSourcedAggregates(),
                ])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\UniqueEventNames',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        )
            ->getFlowTestSupport()
        ;
    }
}
