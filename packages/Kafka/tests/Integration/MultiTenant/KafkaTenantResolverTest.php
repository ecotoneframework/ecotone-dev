<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration\MultiTenant;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;
use RdKafka\Producer;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;
use Test\Ecotone\Kafka\Fixture\MultiTenant\FakeConnectionFactoryStub;
use Test\Ecotone\Kafka\Fixture\MultiTenant\KafkaTenantConsumerExample;

/**
 * @internal
 */
/**
 * licence Enterprise
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class KafkaTenantResolverTest extends TestCase
{
    public function test_resolves_tenant_header_from_kafka_topic_for_inbound_message(): void
    {
        $tenantATopic = 'tenant_a_' . Uuid::v7()->toRfc4122();
        $tenantBTopic = 'tenant_b_' . Uuid::v7()->toRfc4122();

        $consumer = new KafkaTenantConsumerExample([
            'tenant_a_topic' => $tenantATopic,
            'tenant_b_topic' => $tenantBTopic,
        ]);

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaTenantConsumerExample::class],
            [
                $consumer,
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                'tenant_a_connection' => new FakeConnectionFactoryStub(),
                'tenant_b_connection' => new FakeConnectionFactoryStub(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    TopicConfiguration::createWithReferenceName('tenant_a_topic', $tenantATopic),
                    TopicConfiguration::createWithReferenceName('tenant_b_topic', $tenantBTopic),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [$tenantATopic => 'tenant_a_connection', $tenantBTopic => 'tenant_b_connection'],
                        DbalConnectionFactory::class,
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withTransactionOnAsynchronousEndpoints(false)
                        ->withClearAndFlushObjectManagerOnCommandBus(false)
                        ->withDeduplication(false),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->publishToTopic($tenantATopic, 'payload_a');
        $this->publishToTopic($tenantBTopic, 'payload_b');

        $ecotoneLite->run('tenantTopicConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            maxExecutionTimeInMilliseconds: 30000,
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
        $brokerList = ConnectionTestCase::getConnection()->getBootstrapServers()[0];

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
