<?php

declare(strict_types=1);

namespace Monorepo\Benchmark;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\BatchForwardingConfiguration;
use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Measures how fast the whole DBAL outbox is drained and handed over to the next channel of a combined channel:
 * message-by-message forwarding (no enterprise licence) against batched SQL drain-and-forward (enterprise).
 * The consumer is warmed up on an empty outbox before messages are published, so only steady-state relay work is measured.
 * The in-memory target subjects isolate the producing side of the relay; the high throughput target subject shows
 * the full path into a Dbal backed channel receiving whole batches at once.
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
        $this->warmUpConsumerOnEmptyOutbox();
        $this->fillOutbox();
    }

    public function setUpRelayBatched(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE);
        $this->warmUpConsumerOnEmptyOutbox();
        $this->fillOutbox();
    }

    public function setUpRelaySingleBatch(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE, maxForwardingBatchSize: self::AMOUNT_OF_RELAYED_MESSAGES);
        $this->warmUpConsumerOnEmptyOutbox();
        $this->fillOutbox();
    }

    public function setUpRelayBatchedIntoHighThroughputTarget(): void
    {
        $this->messaging = $this->bootstrapOutbox(licenceKey: LicenceTesting::VALID_LICENCE, highThroughputTarget: true);
        $this->warmUpConsumerOnEmptyOutbox();
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

    #[BeforeMethods('setUpRelayBatchedIntoHighThroughputTarget')]
    public function bench_dbal_outbox_drain_batched_into_high_throughput_dbal_target(): void
    {
        $this->drainWholeOutbox();
    }

    private function warmUpConsumerOnEmptyOutbox(): void
    {
        $context = (new DbalConnectionFactory(self::databaseDsn()))->createContext();
        $context->createDataBaseTable();
        $context->purgeQueue($context->createQueue('benchmark_outbox'));
        $context->purgeQueue($context->createQueue('benchmark_target'));

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

    private function bootstrapOutbox(?string $licenceKey, ?int $maxForwardingBatchSize = null, bool $highThroughputTarget = false): FlowTestSupport
    {
        $batchForwardingExtensions = [];
        if ($licenceKey !== null) {
            $batchForwardingConfiguration = BatchForwardingConfiguration::create('benchmark_outbox');
            if ($maxForwardingBatchSize !== null) {
                $batchForwardingConfiguration = $batchForwardingConfiguration->withMaxForwardingBatchSize($maxForwardingBatchSize);
            }
            $batchForwardingExtensions[] = $batchForwardingConfiguration;
        }

        $targetChannel = $highThroughputTarget
            ? DbalBackedMessageChannelBuilder::create('benchmark_target')->withHighThroughputPublishing()
            : SimpleMessageChannelBuilder::createQueueChannel('benchmark_target');

        $orderService = new class () {
            #[Asynchronous('benchmark_relay_orders')]
            #[CommandHandler('benchmark.relayOrder', endpointId: 'benchmarkRelayOrderEndpoint')]
            public function handle(string $order): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [
                DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn()),
                $orderService,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects(array_merge([
                    CombinedMessageChannel::create('benchmark_relay_orders', ['benchmark_outbox', 'benchmark_target']),
                    DbalBackedMessageChannelBuilder::create('benchmark_outbox')
                        ->withReceiveTimeout(20),
                    $targetChannel,
                ], $batchForwardingExtensions)),
            licenceKey: $licenceKey,
        );
    }
}
