<?php

declare(strict_types=1);

namespace Monorepo\Benchmark;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Compares outbox relay throughput over a combined channel (DBAL outbox in front of a broker channel):
 * message-by-message forwarding (no enterprise licence) against batched drain-and-group forwarding (enterprise).
 */
#[Warmup(0), Revs(1), Iterations(5)]
class OutboxRelayBenchmark
{
    private const AMOUNT_OF_RELAYED_MESSAGES = 200;

    private const MESSAGE_PAYLOAD = 'benchmark order payload for outbox relay comparison';

    private FlowTestSupport $messaging;

    public function setUpAmqpRelayMessageByMessage(): void
    {
        $this->messaging = $this->bootstrapOutboxWithTarget(
            ModulePackageList::AMQP_PACKAGE,
            AmqpBackedMessageChannelBuilder::create(uniqid('benchmark_relay_')),
            [AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f'])],
            licenceKey: null,
        );
        $this->fillOutbox();
    }

    public function setUpAmqpRelayBatched(): void
    {
        $this->messaging = $this->bootstrapOutboxWithTarget(
            ModulePackageList::AMQP_PACKAGE,
            AmqpBackedMessageChannelBuilder::create(uniqid('benchmark_relay_'))->withHighThroughputPublishing(),
            [AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f'])],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->fillOutbox();
    }

    public function setUpKafkaRelayMessageByMessage(): void
    {
        $uniqueId = uniqid('benchmark_relay_');
        $this->messaging = $this->bootstrapOutboxWithTarget(
            ModulePackageList::KAFKA_PACKAGE,
            KafkaMessageChannelBuilder::create($uniqueId, topicName: $uniqueId, messageGroupId: $uniqueId),
            [KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([getenv('KAFKA_DSN') ?: 'localhost:9094'])],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->fillOutbox();
    }

    public function setUpKafkaRelayBatched(): void
    {
        $uniqueId = uniqid('benchmark_relay_');
        $this->messaging = $this->bootstrapOutboxWithTarget(
            ModulePackageList::KAFKA_PACKAGE,
            KafkaMessageChannelBuilder::create($uniqueId, topicName: $uniqueId, messageGroupId: $uniqueId)->withHighThroughputPublishing(),
            [KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([getenv('KAFKA_DSN') ?: 'localhost:9094'])],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->fillOutbox();
    }

    #[BeforeMethods('setUpAmqpRelayMessageByMessage')]
    public function bench_amqp_outbox_relay_message_by_message(): void
    {
        $this->relayWholeOutbox();
    }

    #[BeforeMethods('setUpAmqpRelayBatched')]
    public function bench_amqp_outbox_relay_batched(): void
    {
        $this->relayWholeOutbox();
    }

    #[BeforeMethods('setUpKafkaRelayMessageByMessage')]
    public function bench_kafka_outbox_relay_message_by_message(): void
    {
        $this->relayWholeOutbox();
    }

    #[BeforeMethods('setUpKafkaRelayBatched')]
    public function bench_kafka_outbox_relay_batched(): void
    {
        $this->relayWholeOutbox();
    }

    private function relayWholeOutbox(): void
    {
        $this->messaging->run('benchmark_outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }

    private function fillOutbox(): void
    {
        for ($messageNumber = 0; $messageNumber < self::AMOUNT_OF_RELAYED_MESSAGES; $messageNumber++) {
            $this->messaging->sendCommandWithRoutingKey('benchmark.relayOrder', self::MESSAGE_PAYLOAD);
        }
    }

    private function bootstrapOutboxWithTarget(string $modulePackage, object $targetChannelBuilder, array $services, ?string $licenceKey): FlowTestSupport
    {
        $orderService = new class () {
            #[Asynchronous('benchmark_relay_orders')]
            #[CommandHandler('benchmark.relayOrder', endpointId: 'benchmarkRelayOrderEndpoint')]
            public function handle(string $order): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            array_merge($services, [
                DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'),
                $orderService,
            ]),
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE, $modulePackage]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('benchmark_relay_orders', ['benchmark_outbox', $targetChannelBuilder->getMessageChannelName()]),
                    DbalBackedMessageChannelBuilder::create('benchmark_outbox'),
                    $targetChannelBuilder,
                ]),
            licenceKey: $licenceKey,
        );
    }
}
