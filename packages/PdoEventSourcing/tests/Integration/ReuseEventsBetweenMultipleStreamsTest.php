<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams\CertificateProjection;
use Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams\CycleProjection;
use Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams\EventsConverter;
use Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams\RegisterCertificate;

final class ReuseEventsBetweenMultipleStreamsTest extends EventSourcingMessagingTestCase
{
    public function test_events_from_one_stream_can_be_copied_to_another(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new EventsConverter(), new CycleProjection(), new CertificateProjection(), $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotone
            ->sendCommand(new RegisterCertificate(cycleId: 1, certificateId: 1))
            ->sendCommand(new RegisterCertificate(cycleId: 1, certificateId: 2))
            ->sendCommand(new RegisterCertificate(cycleId: 1, certificateId: 3))
        ;

        self::assertEquals(['certificate.1', 'certificate.2', 'certificate.3'], $ecotone->sendQueryWithRouting('cycle.activeCertificates', metadata: ['aggregate.id' => 1]));
//        self::assertFalse($ecotone->sendQueryWithRouting('certificate.isSuspended', metadata: ['aggregate.id' => 1]));
//        self::assertFalse($ecotone->sendQueryWithRouting('certificate.isSuspended', metadata: ['aggregate.id' => 2]));
//        self::assertFalse($ecotone->sendQueryWithRouting('certificate.isSuspended', metadata: ['aggregate.id' => 3]));
//
//        $ecotone->sendCommandWithRoutingKey('certificate.suspend', metadata: ['aggregate.id' => 2]);
//
//        self::assertEquals(['certificate.1', 'certificate.3'], $ecotone->sendQueryWithRouting('cycle.activeCertificates', metadata: ['aggregate.id' => 1]));
//        self::assertFalse($ecotone->sendQueryWithRouting('certificate.isSuspended', metadata: ['aggregate.id' => 1]));
//        self::assertTrue($ecotone->sendQueryWithRouting('certificate.isSuspended', metadata: ['aggregate.id' => 2]));
//        self::assertFalse($ecotone->sendQueryWithRouting('certificate.isSuspended', metadata: ['aggregate.id' => 3]));
    }
}
