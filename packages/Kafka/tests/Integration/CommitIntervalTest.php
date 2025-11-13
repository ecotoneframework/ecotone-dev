<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;
use Test\Ecotone\Kafka\Fixture\CommitInterval\KafkaConsumerWithCommitInterval;
use Test\Ecotone\Kafka\Fixture\CommitInterval\KafkaConsumerWithCommitIntervalAndFailure;
use Test\Ecotone\Kafka\Fixture\CommitInterval\KafkaConsumerWithInterval3;

/**
 * licence Enterprise
 * @internal
 */
final class CommitIntervalTest extends TestCase
{
    public function test_default_commit_interval_commits_every_message(): void
    {
        $topicName = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [KafkaConsumerWithCommitInterval::class],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(), new KafkaConsumerWithCommitInterval()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults($topicName),
                    TopicConfiguration::createWithReferenceName('testTopic', $topicName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send 5 messages
        for ($i = 1; $i <= 5; $i++) {
            $kafkaPublisher->sendWithMetadata("message_$i", 'application/text');
        }

        // Run consumer - should process all 5
        $ecotoneLite->run('kafka_consumer_default', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 5, maxExecutionTimeInMilliseconds: 10000));

        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getMessages');
        $this->assertCount(5, $messages);
        $this->assertEquals(
            ['message_1', 'message_2', 'message_3', 'message_4', 'message_5'],
            array_map(fn ($m) => $m['payload'], $messages)
        );

        // Run consumer again - should NOT reprocess (offset was committed)
        $ecotoneLite->run('kafka_consumer_default', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $messages = $ecotoneLite->sendQueryWithRouting('consumer.getMessages');
        $this->assertCount(5, $messages, 'Messages should not be reprocessed');
    }

    public function test_commit_interval_of_3_commits_at_boundaries(): void
    {
        $topicName = 'interval_' . Uuid::uuid4()->toString();
        $ecotoneLite = $this->bootstrapEcotoneLite($topicName, KafkaConsumerWithInterval3::class);

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send 10 messages
        for ($i = 1; $i <= 10; $i++) {
            $kafkaPublisher->sendWithMetadata("message_$i", 'application/text');
        }

        $ecotoneLite->run('kafka_consumer_interval_3', ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(10));
        $this->assertCount(10, $ecotoneLite->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_commit_interval_with_failure_commits_last_successful(): void
    {
        $topicName = 'failure_' . Uuid::uuid4()->toString();
        $ecotoneLite = $this->bootstrapEcotoneLite($topicName, KafkaConsumerWithCommitIntervalAndFailure::class, $consumerInstance = new KafkaConsumerWithCommitIntervalAndFailure());

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        // Send 10 messages, message 6 will fail
        for ($i = 1; $i <= 9; $i++) {
            $kafkaPublisher->sendWithMetadata("message_$i", 'application/text', ['fail' => $i === 6]);
        }

        // configuration of commit will be adjusted to single message being consumed
        $ecotoneLite->run('kafka_consumer_interval_3_with_failure', ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(15)->withExecutionTimeLimitInMilliseconds(10000));

        // should only continue and not re-reprocess
        $ecotoneLite = $this->bootstrapEcotoneLite($topicName, KafkaConsumerWithCommitIntervalAndFailure::class, $consumerInstance);

        $ecotoneLite->run('kafka_consumer_interval_3_with_failure', ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(10)->withExecutionTimeLimitInMilliseconds(10000));

        $this->assertEquals([
            'message_1', 'message_2', 'message_3', 'message_4', 'message_5',
            'message_6', 'message_7', 'message_8', 'message_9',
        ], array_map(fn ($m) => $m['payload'], $ecotoneLite->sendQueryWithRouting('consumer.getMessages')));
    }

    private function bootstrapEcotoneLite(string $topicName, string $consumerClass, ?object $consumerInstance = null): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$consumerClass],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(), $consumerInstance ?? new $consumerClass()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults($topicName),
                    TopicConfiguration::createWithReferenceName('testTopic', $topicName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
