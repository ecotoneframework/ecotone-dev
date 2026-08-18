<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration\DynamicTopics;

use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;
use RdKafka\Producer;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;
use Test\Ecotone\Kafka\Fixture\DynamicTopics\DynamicTopicsKafkaConsumer;
use Test\Ecotone\Kafka\Fixture\DynamicTopics\MixedTopicsKafkaConsumer;
use Test\Ecotone\Kafka\Fixture\DynamicTopics\ParameterTopicsKafkaConsumer;
use Test\Ecotone\Kafka\Fixture\DynamicTopics\TopicsProvider;

/**
 * licence Enterprise
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class KafkaConsumerExpressionTopicsTest extends TestCase
{
    public function test_topics_expression_is_evaluated_and_subscribes_to_resolved_topic(): void
    {
        $topicName = 'dynamic_orders_' . Uuid::v7()->toRfc4122();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [DynamicTopicsKafkaConsumer::class],
            [
                new DynamicTopicsKafkaConsumer(),
                'topicsProvider' => new TopicsProvider(),
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    TopicConfiguration::createWithReferenceName('dynamicOrdersTopic', $topicName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->publishToTopic($topicName, 'order-placed');

        $ecotoneLite->run('dynamicTopicsConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 30000,
        ));

        $this->assertSame(
            ['order-placed'],
            $ecotoneLite->sendQueryWithRouting('dynamicTopicsConsumer.getMessages'),
            'Expression-provided topic reference name should have been resolved and subscribed to.',
        );
    }

    public function test_mixed_literal_and_expression_topics_are_both_subscribed(): void
    {
        $literalTopicName = 'literal_orders_' . Uuid::v7()->toRfc4122();
        $dynamicTopicName = 'dynamic_orders_' . Uuid::v7()->toRfc4122();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [MixedTopicsKafkaConsumer::class],
            [
                new MixedTopicsKafkaConsumer(),
                'topicsProvider' => new TopicsProvider(),
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    TopicConfiguration::createWithReferenceName('literalOrdersTopic', $literalTopicName),
                    TopicConfiguration::createWithReferenceName('dynamicOrdersTopic', $dynamicTopicName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->publishToTopic($literalTopicName, 'literal-order-placed');
        $this->publishToTopic($dynamicTopicName, 'dynamic-order-placed');

        $ecotoneLite->run('mixedTopicsConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            maxExecutionTimeInMilliseconds: 30000,
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('mixedTopicsConsumer.getMessages');
        sort($messages);

        $this->assertSame(
            ['dynamic-order-placed', 'literal-order-placed'],
            $messages,
            'Both the literal topic and the expression-resolved topic should have been subscribed to.',
        );
    }

    public function test_parameter_function_resolves_topic_via_configuration_variable_service(): void
    {
        $topicName = 'dynamic_orders_' . Uuid::v7()->toRfc4122();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ParameterTopicsKafkaConsumer::class],
            [
                new ParameterTopicsKafkaConsumer(),
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    TopicConfiguration::createWithReferenceName('dynamicOrdersTopic', $topicName),
                ]),
            configurationVariables: ['ordersTopicReferenceName' => 'dynamicOrdersTopic'],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->publishToTopic($topicName, 'order-placed-via-parameter');

        $ecotoneLite->run('parameterTopicsConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 30000,
        ));

        $this->assertSame(
            ['order-placed-via-parameter'],
            $ecotoneLite->sendQueryWithRouting('parameterTopicsConsumer.getMessages'),
            "parameter('ordersTopicReferenceName') should resolve via ConfigurationVariableService to the real topic reference name.",
        );
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
