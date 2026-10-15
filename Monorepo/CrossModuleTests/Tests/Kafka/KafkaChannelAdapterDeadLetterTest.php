<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Kafka;

use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\Kafka\KafkaPublisherConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

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
final class KafkaChannelAdapterDeadLetterTest extends TestCase
{
    public function test_default_error_channel_on_consumer_with_dead_letter_and_replay(): void
    {
        $topicName = 'test_topic_dead_letter_' . Uuid::v7()->toRfc4122();
        $consumerReferenceName = 'kafka_consumer_attribute';
        $failureCount = 0;

        $consumer = new class () {
            public int $failureCount = 0;
            private array $processedMessages = [];

            #[KafkaConsumer('kafka_consumer_attribute', 'testTopicDeadLetter')]
            public function handle(#[\Ecotone\Api\Attribute\Payload] string $payload): void
            {
                if ($this->failureCount < 1) {
                    $this->failureCount++;
                    throw new RuntimeException('Simulated failure');
                }
                $this->processedMessages[] = $payload;
            }

            #[QueryHandler('consumer.getProcessedMessages')]
            public function getProcessedMessages(): array
            {
                return $this->processedMessages;
            }
        };

        $dbalConnectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@database:5432/ecotone');
        $this->cleanDeadLetterTable($dbalConnectionFactory);

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$consumer::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                DbalConnectionFactory::class => $dbalConnectionFactory,
                'managerRegistry' => $dbalConnectionFactory,
                $consumer,
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName)->withHeaderMapper('*'),
                TopicConfiguration::createWithReferenceName('testTopicDeadLetter',$topicName),
                KafkaConsumerConfiguration::createWithDefaults($consumerReferenceName),
                DbalConfiguration::createWithDefaults(),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE,ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->send($payload);

        $ecotoneLite->run($consumerReferenceName, ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            executionTimeLimitInMilliseconds: 30000,
            stopOnError: false,
        ));

        /** @var DeadLetterGateway $deadLetter */
        $deadLetter = $ecotoneLite->getGateway(DeadLetterGateway::class);
        $this->assertEquals(1, $deadLetter->count());

        $deadLetter->replayAll();
        $this->assertEquals(0, $deadLetter->count());

        $ecotoneLite->run($consumerReferenceName, ExecutionPollingMetadata::createWithTestingSetup(
            executionTimeLimitInMilliseconds: 30000,
            stopOnError: false,
        ));

        $processedMessages = $ecotoneLite->sendQueryWithRouting('consumer.getProcessedMessages');
        $this->assertCount(1, $processedMessages);
        $this->assertEquals($payload, $processedMessages[0]);
    }

    public function test_inbound_channel_adapter_failure_lands_in_dead_letter_and_replays_back_to_handler(): void
    {
        $topicName = 'test_topic_replayable_' . Uuid::v7()->toRfc4122();
        $handler = new class () {
            public const ENDPOINT_ID = 'replayable_kafka_consumer';
            public const TOPIC_REFERENCE = 'replayableKafkaTopic';

            public bool $shouldFail = true;
            public int $invocations = 0;
            /** @var string[] */
            public array $processedPayloads = [];

            #[KafkaConsumer(self::ENDPOINT_ID, self::TOPIC_REFERENCE)]
            public function handle(#[\Ecotone\Api\Attribute\Payload] string $payload): void
            {
                $this->invocations++;
                if ($this->shouldFail) {
                    throw new RuntimeException('simulated');
                }
                $this->processedPayloads[] = $payload;
            }
        };

        $dbalConnectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@database:5432/ecotone');
        $this->cleanDeadLetterTable($dbalConnectionFactory);

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                DbalConnectionFactory::class => $dbalConnectionFactory,
                'managerRegistry' => $dbalConnectionFactory,
                $handler,
            ],
            extensionObjects: [
                KafkaPublisherConfiguration::createWithDefaults($topicName)->withHeaderMapper('*'),
                TopicConfiguration::createWithReferenceName('replayableKafkaTopic',$topicName),
                KafkaConsumerConfiguration::createWithDefaults('replayable_kafka_consumer'),
                DbalConfiguration::createWithDefaults(),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE,ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $payload = Uuid::v7()->toRfc4122();
        $messagePublisher = $ecotoneLite->getGateway(MessagePublisher::class);
        $messagePublisher->send($payload);

        $ecotoneLite->run('replayable_kafka_consumer', ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            executionTimeLimitInMilliseconds: 30000,
            stopOnError: false,
        ));

        /** @var DeadLetterGateway $deadLetter */
        $deadLetter = $ecotoneLite->getGateway(DeadLetterGateway::class);
        $this->assertEquals(1, $deadLetter->count(), 'Failed Kafka Message must land in DBAL Dead Letter');
        $this->assertSame(1, $handler->invocations);
        $this->assertSame([], $handler->processedPayloads);

        $handler->shouldFail = false;
        $deadLetter->replayAll();

        $this->assertEquals(0, $deadLetter->count());
        $this->assertSame(2, $handler->invocations, 'replayAll() must synchronously re-invoke the handler via MessagingEntrypoint — no second run() needed');
        $this->assertSame([$payload], $handler->processedPayloads, 'Replayed Message must carry the original payload back to the handler');

        $ecotoneLite->run('replayable_kafka_consumer', ExecutionPollingMetadata::createWithTestingSetup(
            executionTimeLimitInMilliseconds: 30000,
            stopOnError: false,
        ));

        $this->assertSame(2, $handler->invocations, 'Replayed Message must not be re-consumed from the Kafka topic (committed)');
        $this->assertSame([$payload], $handler->processedPayloads, 'Processed payloads must remain unchanged after a second poll');
    }

    private function cleanDeadLetterTable(DbalConnectionFactory $connectionFactory): void
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist(['ecotone_error_messages'])) {
            $connection->executeStatement('DELETE FROM ecotone_error_messages');
        }
    }
}
