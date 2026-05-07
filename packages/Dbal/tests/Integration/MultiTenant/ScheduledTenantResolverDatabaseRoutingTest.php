<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ScheduledTenantResolverDatabaseRoutingTest extends DbalMessagingTestCase
{
    public function test_inserts_routed_to_per_tenant_database_when_handler_is_asynchronous(): void
    {
        [$tenantADbal, $tenantBDbal] = $this->resetTenantTables();

        $poller = $this->newPoller();

        $handler = new class () {
            #[Asynchronous('persons_processing')]
            #[CommandHandler('insertPerson', endpointId: 'insertPersonEndpoint')]
            public function handle(
                int $personId,
                #[Header('person_name')] string $name,
                #[Reference(DbalConnectionFactory::class)] ConnectionFactory $factory,
            ): void {
                $factory->createContext()->getDbalConnection()->executeStatement(
                    'INSERT INTO persons (person_id, name) VALUES (?, ?)',
                    [$personId, $name]
                );
            }
        };

        $ecotone = $this->bootstrap([$poller, $handler], asynchronous: true);

        $ecotone->run('externalPersonPoller', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $ecotone->run('persons_processing', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $ecotone->run('externalPersonPoller', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $ecotone->run('persons_processing', ExecutionPollingMetadata::createWithTestingSetup(1, 1));

        $this->assertTenantTablesIsolated($tenantADbal, $tenantBDbal);
    }

    public function test_inserts_routed_to_per_tenant_database_when_handler_is_synchronous(): void
    {
        [$tenantADbal, $tenantBDbal] = $this->resetTenantTables();

        $poller = $this->newPoller();

        $handler = new class () {
            #[CommandHandler('insertPerson', endpointId: 'insertPersonEndpoint')]
            public function handle(
                int $personId,
                #[Header('person_name')] string $name,
                #[Reference(DbalConnectionFactory::class)] ConnectionFactory $factory,
            ): void {
                $factory->createContext()->getDbalConnection()->executeStatement(
                    'INSERT INTO persons (person_id, name) VALUES (?, ?)',
                    [$personId, $name]
                );
            }
        };

        $ecotone = $this->bootstrap([$poller, $handler], asynchronous: false);

        $ecotone->run('externalPersonPoller', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $ecotone->run('externalPersonPoller', ExecutionPollingMetadata::createWithTestingSetup(1, 1));

        $this->assertTenantTablesIsolated($tenantADbal, $tenantBDbal);
    }

    /**
     * @return array{0: Connection, 1: Connection}
     */
    private function resetTenantTables(): array
    {
        $tenantADbal = $this->connectionForTenantA()->createContext()->getDbalConnection();
        $tenantBDbal = $this->connectionForTenantB()->createContext()->getDbalConnection();
        $tenantADbal->executeStatement('DROP TABLE IF EXISTS persons');
        $tenantBDbal->executeStatement('DROP TABLE IF EXISTS persons');
        $this->setupUserTable($tenantADbal);
        $this->setupUserTable($tenantBDbal);

        return [$tenantADbal, $tenantBDbal];
    }

    private function newPoller(): object
    {
        return new class ([
            ['source' => 'tenant_b', 'personId' => 200, 'name' => 'Bob'],
            ['source' => 'tenant_a', 'personId' => 100, 'name' => 'Alice'],
        ]) {
            public function __construct(private array $pending)
            {
            }

            #[Scheduled(requestChannelName: 'insertPerson', endpointId: 'externalPersonPoller')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function poll(): ?Message
            {
                if ($this->pending === []) {
                    return null;
                }
                $event = array_shift($this->pending);
                return MessageBuilder::withPayload($event['personId'])
                    ->setHeader('person_name', $event['name'])
                    ->setHeader('source', $event['source'])
                    ->build();
            }
        };
    }

    /**
     * @param object[] $services
     */
    private function bootstrap(array $services, bool $asynchronous): FlowTestSupport
    {
        $extensionObjects = [
            PollingMetadata::create('externalPersonPoller')
                ->setExecutionAmountLimit(1)
                ->setHandledMessageLimit(1),
            MultiTenantConfiguration::create(
                tenantHeaderName: 'tenant',
                tenantToConnectionMapping: [
                    'tenant_a' => 'tenant_a_connection',
                    'tenant_b' => 'tenant_b_connection',
                ],
            ),
            DbalConfiguration::createWithDefaults()
                ->withTransactionOnCommandBus(false)
                ->withTransactionOnAsynchronousEndpoints(false)
                ->withClearAndFlushObjectManagerOnCommandBus(false)
                ->withDeduplication(false),
        ];
        if ($asynchronous) {
            $extensionObjects[] = PollingMetadata::create('persons_processing')
                ->setExecutionAmountLimit(1)
                ->setHandledMessageLimit(1);
        }

        return EcotoneLite::bootstrapFlowTesting(
            array_map(static fn (object $service): string => $service::class, $services),
            array_merge(
                $services,
                [
                    'tenant_a_connection' => $this->connectionForTenantA(),
                    'tenant_b_connection' => $this->connectionForTenantB(),
                ],
            ),
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects($extensionObjects),
            enableAsynchronousProcessing: $asynchronous
                ? [SimpleMessageChannelBuilder::createQueueChannel('persons_processing')]
                : true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function assertTenantTablesIsolated(Connection $tenantADbal, Connection $tenantBDbal): void
    {
        $this->assertSame(
            [['person_id' => 100, 'name' => 'Alice']],
            $this->fetchPersons($tenantADbal),
            'tenant_a database must contain only the tenant_a record. WithTenantResolver routes the inbound message via headers[source] -> tenant header -> tenant_a connection.'
        );
        $this->assertSame(
            [['person_id' => 200, 'name' => 'Bob']],
            $this->fetchPersons($tenantBDbal),
            'tenant_b database must contain only the tenant_b record. Cross-tenant leakage would mean tenant routing failed.'
        );
    }

    /**
     * @return array<int, array{person_id: int, name: string}>
     */
    private function fetchPersons(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative('SELECT person_id, name FROM persons ORDER BY person_id');
        return array_map(
            fn (array $row): array => ['person_id' => (int) $row['person_id'], 'name' => (string) $row['name']],
            $rows
        );
    }
}
