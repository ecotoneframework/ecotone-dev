<?php

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\AsyncCycle;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\AsyncCycleGateway;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\Audit;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\AuditConducted;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\Certificate;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\CertificateIssued;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\CycleStarted;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow\EventsConverters;

/**
 * @internal
 */
class StatefulAsyncEventSourcedWorkflowTest extends EventSourcingMessagingTestCase
{
    public function test_stateful_async_event_sourced_workflow(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $cycleGateway = $ecotone->getGateway(AsyncCycleGateway::class);

        $cycleGateway->submitAnAudit(cycleId: 'foo', audit: new Audit('123', new Certificate('234')));

        self::assertEquals(
            [
                new CycleStarted('foo'),
                new AuditConducted('foo', '123'),
            ],
            $ecotone->getRecordedEvents()
        );

        self::assertEquals(['123'], $cycleGateway->conductedAudits('foo'));
        self::assertEquals([], $cycleGateway->issuedCertificates('foo'));

        self::assertEquals(
            [
                new CertificateIssued('foo', '234'),
            ],
            $ecotone->run('cycle')->getRecordedEvents()
        );

        self::assertEquals(['123'], $cycleGateway->conductedAudits('foo'));
        self::assertEquals(['234'], $cycleGateway->issuedCertificates('foo'));
    }

    public function test_stateful_async_event_sourced_workflow_with_existing_aggregate(): void
    {
        $ecotone = $this->bootstrapEcotone()
            ->withEventsFor(
                'foo',
                AsyncCycle::class,
                [
                    new CycleStarted('foo'),
                    new AuditConducted('foo', '123'),
                    new CertificateIssued('foo', '234'),
                ]
            )
        ;

        $cycleGateway = $ecotone->getGateway(AsyncCycleGateway::class);

        $cycleGateway->submitAnAudit(cycleId: 'foo', audit: new Audit('678', new Certificate('987')));

        self::assertEquals(
            [
                new AuditConducted('foo', '678'),
            ],
            $ecotone->getRecordedEvents()
        );

        self::assertEquals(['123', '678'], $cycleGateway->conductedAudits('foo'));
        self::assertEquals(['234'], $cycleGateway->issuedCertificates('foo'));

        self::assertEquals(
            [
                new CertificateIssued('foo', '987'),
            ],
            $ecotone->run('cycle')->getRecordedEvents()
        );

        self::assertEquals(['123', '678'], $cycleGateway->conductedAudits('foo'));
        self::assertEquals(['234', '987'], $cycleGateway->issuedCertificates('foo'));
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                AsyncCycle::class,
                AsyncCycleGateway::class,
                EventsConverters::class,
            ],
            containerOrAvailableServices: [
                new EventsConverters(),
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow']),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('cycle')],
        );
    }
}
