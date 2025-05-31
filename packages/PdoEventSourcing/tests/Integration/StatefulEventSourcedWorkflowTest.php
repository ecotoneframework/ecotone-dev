<?php

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\Audit;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\AuditConducted;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\Certificate;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\CertificateIssued;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\Cycle;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\CycleGateway;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\CycleStarted;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\EventsConverters;

class StatefulEventSourcedWorkflowTest extends EventSourcingMessagingTestCase
{
    public function test_stateful_event_sourced_workflow(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                Cycle::class,
                CycleGateway::class,
                EventsConverters::class,
            ],
            containerOrAvailableServices: [
                new EventsConverters(),
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Modelling\Fixture\StatefulEventSourcedWorkflow']),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
        );

        $cycleGateway = $ecotone->getGateway(CycleGateway::class);

        $cycleGateway->submitAnAudit(cycleId: 'foo', audit: new Audit('123', new Certificate('234')));

        self::assertEquals(
            [
                new CycleStarted('foo'),
                new AuditConducted('foo', '123'),
                new CertificateIssued('foo', '234'),
            ],
            $ecotone->getRecordedEvents()
        );

        $cycleGateway->submitAnAudit(cycleId: 'foo', audit: new Audit('678'));

        self::assertEquals(
            [
                new AuditConducted('foo', '678'),
            ],
            $ecotone->getRecordedEvents()
        );

        self::assertEquals(['123', '678'], $cycleGateway->conductedAudits('foo'));
        self::assertEquals(['234'], $cycleGateway->issuedCertificates('foo'));
    }
}
