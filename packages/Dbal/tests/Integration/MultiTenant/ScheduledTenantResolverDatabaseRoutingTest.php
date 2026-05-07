<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Messaging\Attribute\Parameter\Reference;
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
    public function test_inserts_routed_to_per_tenant_database_via_resolved_tenant_header(): void
    {
        $tenantADbal = $this->connectionForTenantA()->createContext()->getDbalConnection();
        $tenantBDbal = $this->connectionForTenantB()->createContext()->getDbalConnection();
        $tenantADbal->executeStatement('DROP TABLE IF EXISTS persons');
        $tenantBDbal->executeStatement('DROP TABLE IF EXISTS persons');
        $this->setupUserTable($tenantADbal);
        $this->setupUserTable($tenantBDbal);

        $poller = new class ([
            ['source' => 'tenant_a', 'personId' => 100, 'name' => 'Alice'],
            ['source' => 'tenant_b', 'personId' => 200, 'name' => 'Bob'],
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

        $handler = new class () {
            #[Asynchronous('persons_processing')]
            #[CommandHandler('insertPerson', endpointId: 'insertPersonEndpoint')]
            public function handle(
                int $personId,
                #[Header('person_name')] string $name,
                #[Reference(DbalConnectionFactory::class)] ConnectionFactory $factory
            ): void {
                $factory->createContext()->getDbalConnection()->executeStatement(
                    'INSERT INTO persons (person_id, name) VALUES (?, ?)',
                    [$personId, $name]
                );
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$poller::class, $handler::class],
            [
                $poller,
                $handler,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    PollingMetadata::create('externalPersonPoller')
                        ->setExecutionAmountLimit(1)
                        ->setHandledMessageLimit(1),
                    PollingMetadata::create('persons_processing')
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
                ]),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('persons_processing'),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->run('externalPersonPoller', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $ecotone->run('persons_processing', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $ecotone->run('externalPersonPoller', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $ecotone->run('persons_processing', ExecutionPollingMetadata::createWithTestingSetup(1, 1));

        $tenantARows = $this->fetchPersons($tenantADbal);
        $tenantBRows = $this->fetchPersons($tenantBDbal);

        $this->assertSame(
            [['person_id' => 100, 'name' => 'Alice']],
            $tenantARows,
            'tenant_a database must contain only the tenant_a record. WithTenantResolver routes the inbound message via headers[source] -> tenant header -> tenant_a connection.'
        );
        $this->assertSame(
            [['person_id' => 200, 'name' => 'Bob']],
            $tenantBRows,
            'tenant_b database must contain only the tenant_b record. Cross-tenant leakage would mean tenant routing failed.'
        );
    }

    /**
     * @return array<int, array{person_id: int, name: string}>
     */
    private function fetchPersons(\Doctrine\DBAL\Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative('SELECT person_id, name FROM persons ORDER BY person_id');
        return array_map(
            fn (array $row): array => ['person_id' => (int) $row['person_id'], 'name' => (string) $row['name']],
            $rows
        );
    }
}
