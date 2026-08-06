<?php

declare(strict_types=1);

namespace Monorepo\Benchmark;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\OutboxForwardingMessageChannel;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Redis\RedisConnectionFactory;
use Enqueue\Sqs\SqsConnectionFactory;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Measures how fast the whole DBAL outbox is drained and handed over to the next channel of a combined channel:
 * message-by-message forwarding (no enterprise licence) against batched SQL drain-and-forward (enterprise).
 * The consumer is warmed up before messages are published, so only steady-state relay work is measured.
 * The in-memory target subjects isolate the producing side of the relay; the provider subjects show the full
 * path into real brokers receiving whole batches at once.
 */
#[Warmup(0), Revs(1), Iterations(5)]
class OutboxRelayBenchmark
{
    private const AMOUNT_OF_RELAYED_MESSAGES = 10_000;

    private const MESSAGE_PAYLOAD = 'benchmark order payload for outbox relay comparison';

    private FlowTestSupport $messaging;

    public function setUpRelayMessageByMessage(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: null);
        $this->warmUpConsumer();
        $this->fillOutbox();
    }

    public function setUpRelayBatched(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE);
        $this->warmUpConsumer();
        $this->fillOutbox();
    }

    public function setUpRelaySingleBatch(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE, maxForwardingBatchSize: self::AMOUNT_OF_RELAYED_MESSAGES);
        $this->warmUpConsumer();
        $this->fillOutbox();
    }

    public function setUpRelayBatchedIntoDbalTarget(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE, targetProvider: 'dbal');
        $this->warmUpConsumer();
        $this->fillOutbox();
    }

    public function setUpRelayBatchedIntoAmqpTarget(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE, targetProvider: 'amqp');
        $this->warmUpConsumer();
        $this->fillOutbox();
    }

    public function setUpRelayBatchedIntoKafkaTarget(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE, targetProvider: 'kafka');
        $this->warmUpConsumer();
        $this->fillOutbox();
    }

    public function setUpRelayBatchedIntoRedisTarget(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE, targetProvider: 'redis');
        $this->warmUpConsumer();
        $this->fillOutbox();
    }

    public function setUpRelayBatchedIntoSqsTarget(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE, targetProvider: 'sqs');
        $this->warmUpConsumer();
        $this->fillOutbox();
    }

    #[BeforeMethods('setUpRelayMessageByMessage')]
    public function bench_dbal_outbox_drain_message_by_message(): void
    {
        $this->drainWholeOutbox();
    }

    #[BeforeMethods('setUpRelayBatched')]
    public function bench_dbal_outbox_drain_batched(): void
    {
        $this->drainWholeOutbox();
    }

    #[BeforeMethods('setUpRelaySingleBatch')]
    public function bench_dbal_outbox_drain_as_single_batch(): void
    {
        $this->drainWholeOutbox();
    }

    #[BeforeMethods('setUpRelayBatchedIntoDbalTarget')]
    public function bench_dbal_outbox_drain_batched_into_high_throughput_dbal_target(): void
    {
        $this->drainWholeOutbox();
    }

    #[BeforeMethods('setUpRelayBatchedIntoAmqpTarget'), Iterations(3)]
    public function bench_dbal_outbox_drain_batched_into_rabbitmq_target(): void
    {
        $this->drainWholeOutbox();
    }

    #[BeforeMethods('setUpRelayBatchedIntoKafkaTarget'), Iterations(3)]
    public function bench_dbal_outbox_drain_batched_into_kafka_target(): void
    {
        $this->drainWholeOutbox();
    }

    #[BeforeMethods('setUpRelayBatchedIntoRedisTarget'), Iterations(3)]
    public function bench_dbal_outbox_drain_batched_into_redis_target(): void
    {
        $this->drainWholeOutbox();
    }

    #[BeforeMethods('setUpRelayBatchedIntoSqsTarget'), Iterations(3)]
    public function bench_dbal_outbox_drain_batched_into_sqs_target(): void
    {
        $this->drainWholeOutbox();
    }

    private function warmUpConsumer(): void
    {
        $context = (new DbalConnectionFactory(self::databaseDsn()))->createContext();
        $context->createDataBaseTable();
        $context->purgeQueue($context->createQueue('benchmark_outbox'));
        $context->purgeQueue($context->createQueue('benchmark_target'));

        $this->messaging->sendCommandWithRoutingKey('benchmark.relayOrder', self::MESSAGE_PAYLOAD);
        $this->messaging->run('benchmark_outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }

    private function drainWholeOutbox(): void
    {
        $this->messaging->run('benchmark_outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }

    private static function databaseDsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    }

    private function fillOutbox(): void
    {
        for ($messageNumber = 0; $messageNumber < self::AMOUNT_OF_RELAYED_MESSAGES; $messageNumber++) {
            $this->messaging->sendCommandWithRoutingKey('benchmark.relayOrder', self::MESSAGE_PAYLOAD);
        }
    }

    private function bootstrapOutbox(?string $licenceKey, ?int $maxForwardingBatchSize = null, string $targetProvider = 'in_memory'): FlowTestSupport
    {
        $targetName = in_array($targetProvider, ['in_memory', 'dbal'], true) ? 'benchmark_target' : uniqid('benchmark_target_');
        if ($licenceKey !== null) {
            $relayChannel = OutboxForwardingMessageChannel::create('benchmark_relay_orders', 'benchmark_outbox', $targetName);
            if ($maxForwardingBatchSize !== null) {
                $relayChannel = $relayChannel->withMaxForwardingBatchSize($maxForwardingBatchSize);
            }
        } else {
            $relayChannel = CombinedMessageChannel::create('benchmark_relay_orders', ['benchmark_outbox', $targetName]);
        }
        [$targetChannel, $targetServices, $targetPackage] = match ($targetProvider) {
            'in_memory' => [SimpleMessageChannelBuilder::createQueueChannel($targetName), [], null],
            'dbal' => [DbalBackedMessageChannelBuilder::create($targetName)->withHighThroughputPublishing(), [], null],
            'amqp' => [
                AmqpBackedMessageChannelBuilder::create($targetName)->withHighThroughputPublishing(),
                [AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f'])],
                ModulePackageList::AMQP_PACKAGE,
            ],
            'kafka' => [
                KafkaMessageChannelBuilder::create($targetName, topicName: $targetName, messageGroupId: $targetName)->withHighThroughputPublishing(),
                [KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([getenv('KAFKA_DSN') ?: 'localhost:9094'])],
                ModulePackageList::KAFKA_PACKAGE,
            ],
            'redis' => [
                RedisBackedMessageChannelBuilder::create($targetName)->withHighThroughputPublishing(),
                [RedisConnectionFactory::class => new RedisConnectionFactory(getenv('REDIS_DSN') ?: 'redis://localhost:6379')],
                ModulePackageList::REDIS_PACKAGE,
            ],
            'sqs' => [
                SqsBackedMessageChannelBuilder::create($targetName)->withHighThroughputPublishing(),
                [SqsConnectionFactory::class => new SqsConnectionFactory(getenv('SQS_DSN') ?: 'sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localhost:4566&version=latest')],
                ModulePackageList::SQS_PACKAGE,
            ],
        };

        $orderService = new class () {
            #[Asynchronous('benchmark_relay_orders')]
            #[CommandHandler('benchmark.relayOrder', endpointId: 'benchmarkRelayOrderEndpoint')]
            public function handle(string $order): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            array_merge(
                [
                    DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn()),
                    $orderService,
                ],
                $targetServices,
            ),
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept(array_merge(
                    [ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE],
                    $targetPackage !== null ? [$targetPackage] : [],
                )))
                ->withExtensionObjects([
                    $relayChannel,
                    DbalBackedMessageChannelBuilder::create('benchmark_outbox')
                        ->withReceiveTimeout(20),
                    $targetChannel,
                ]),
            licenceKey: $licenceKey,
        );
    }
}
