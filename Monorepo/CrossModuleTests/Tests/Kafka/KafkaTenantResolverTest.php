<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Kafka;

use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\Attribute\WithTenantResolver;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Test\LicenceTesting;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use LogicException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;
use RdKafka\Producer;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

/**
 * @internal
 */
/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class KafkaTenantResolverTest extends TestCase
{
    public function test_resolves_tenant_header_from_kafka_topic_for_inbound_message(): void
    {
        $tenantATopic = 'tenant_a_' . Uuid::v7()->toRfc4122();
        $tenantBTopic = 'tenant_b_' . Uuid::v7()->toRfc4122();

        $consumer = new class () {
            /** @var array<int, array<string, mixed>> */
            private array $captured = [];

            #[KafkaConsumer('tenantTopicConsumer', topics: ['tenant_a_topic', 'tenant_b_topic'])]
            #[WithTenantResolver(expression: "headers['kafka_topic']")]
            public function handle(string $payload, #[Headers] array $headers): void
            {
                $this->captured[] = $headers;
            }

            /**
             * @return array<string, mixed>|null
             */
            #[QueryHandler('consumer.lastCapturedHeaders')]
            public function lastCapturedHeaders(): ?array
            {
                return array_shift($this->captured);
            }
        };

        $stubConnection = new class () implements ConnectionFactory {
            public function createContext(): Context
            {
                throw new LogicException('Tenant resolver test does not exercise downstream connection use.');
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$consumer::class],
            [
                $consumer,
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                'tenant_a_connection' => $stubConnection,
                'tenant_b_connection' => $stubConnection,
            ],
            extensionObjects: [
                TopicConfiguration::createWithReferenceName('tenant_a_topic',$tenantATopic),
                TopicConfiguration::createWithReferenceName('tenant_b_topic',$tenantBTopic),
                MultiTenantConfiguration::create('tenant',[$tenantATopic=>'tenant_a_connection',$tenantBTopic=>'tenant_b_connection'],DbalConnectionFactory::class,),
                DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false)->withClearAndFlushObjectManagerOnCommandBus(false)->withDeduplication(false),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE,ModulePackageList::KAFKA_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->publishToTopic($tenantATopic, 'payload_a');
        $this->publishToTopic($tenantBTopic, 'payload_b');

        $ecotoneLite->run('tenantTopicConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 2,
            executionTimeLimitInMilliseconds: 30000,
        ));

        $headersList = [];
        while (($captured = $ecotoneLite->sendQueryWithRouting('consumer.lastCapturedHeaders')) !== null) {
            $headersList[] = $captured;
        }

        $this->assertCount(2, $headersList, 'Both Kafka messages should have been consumed.');

        $byTenant = [];
        foreach ($headersList as $headers) {
            $this->assertArrayHasKey('tenant', $headers, 'Resolver should inject tenant header derived from kafka_topic.');
            $byTenant[$headers['tenant']] = $headers;
        }

        $this->assertArrayHasKey($tenantATopic, $byTenant, 'Message from tenant_a topic should land with tenant=' . $tenantATopic);
        $this->assertArrayHasKey($tenantBTopic, $byTenant, 'Message from tenant_b topic should land with tenant=' . $tenantBTopic);
        $this->assertSame($tenantATopic, $byTenant[$tenantATopic]['kafka_topic'] ?? null, 'Resolved tenant must equal the originating kafka_topic header.');
        $this->assertSame($tenantBTopic, $byTenant[$tenantBTopic]['kafka_topic'] ?? null);
    }

    private function publishToTopic(string $topic, string $payload): void
    {
        $brokerList = ConnectionTestCase::getConnection()->bootstrapServers[0];

        $conf = new Conf();
        $conf->set('metadata.broker.list', $brokerList);
        $conf->set('socket.timeout.ms', '50');
        $producer = new Producer($conf);

        $kafkaTopic = $producer->newTopic($topic);
        $kafkaTopic->produce(RD_KAFKA_PARTITION_UA, 0, $payload);
        $producer->poll(0);

        for ($i = 0; $i < 50 && $producer->getOutQLen() > 0; $i++) {
            $producer->poll(50);
        }
    }
}
