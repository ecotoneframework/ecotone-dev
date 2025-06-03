<?php

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\StatefulWorkflow\AsyncCycle;
use Test\Ecotone\Dbal\Fixture\StatefulWorkflow\AsyncCycleGateway;
use Test\Ecotone\Dbal\Fixture\StatefulWorkflow\Audit;
use Test\Ecotone\Dbal\Fixture\StatefulWorkflow\AuditConducted;
use Test\Ecotone\Dbal\Fixture\StatefulWorkflow\Certificate;
use Test\Ecotone\Dbal\Fixture\StatefulWorkflow\CertificateIssued;
use Test\Ecotone\Dbal\Fixture\StatefulWorkflow\EventsConverters;

/**
 * @internal
 */
class StatefulAsyncWorkflowTest extends DbalMessagingTestCase
{
    public function test_stateful_async_workflow(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $cycleGateway = $ecotone->getGateway(AsyncCycleGateway::class);

        $cycleGateway->submitAnAudit(cycleId: 'foo', audit: new Audit('123', new Certificate('234')));

        self::assertEquals(
            [
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

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                AsyncCycle::class,
                AsyncCycleGateway::class,
                EventsConverters::class,
            ],
            containerOrAvailableServices: [
                new EventsConverters(),
                $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Dbal\Fixture\StatefulWorkflow']),
            pathToRootCatalog: __DIR__ . '/../../',
            enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('cycle')]
        );
    }
}
