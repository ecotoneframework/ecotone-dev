<?php

declare(strict_types=1);

namespace Monorepo\Benchmark;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use Monorepo\Benchmark\AsyncPublishing\OrderPublisherService;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Warmup(1), Revs(5), Iterations(3)]
class AsyncPublishingBenchmark
{
    private const AMOUNT_OF_PUBLISHED_MESSAGES = 100;

    private FlowTestSupport $messaging;

    public function setUpAmqpSynchronousPublishing(): void
    {
        $this->messaging = $this->bootstrapWithAmqp(asyncPublishing: false);
    }

    public function setUpAmqpAsyncPublishing(): void
    {
        $this->messaging = $this->bootstrapWithAmqp(asyncPublishing: true);
    }

    public function setUpKafkaSynchronousPublishing(): void
    {
        $this->messaging = $this->bootstrapWithKafka(asyncPublishing: false);
    }

    public function setUpKafkaAsyncPublishing(): void
    {
        $this->messaging = $this->bootstrapWithKafka(asyncPublishing: true);
    }

    #[BeforeMethods('setUpAmqpSynchronousPublishing')]
    public function bench_amqp_synchronous_publishing(): void
    {
        $this->publishMessagesThroughCommandHandler();
    }

    #[BeforeMethods('setUpAmqpAsyncPublishing')]
    public function bench_amqp_async_publishing(): void
    {
        $this->publishMessagesThroughCommandHandler();
    }

    #[BeforeMethods('setUpKafkaSynchronousPublishing')]
    public function bench_kafka_synchronous_publishing(): void
    {
        $this->publishMessagesThroughCommandHandler();
    }

    #[BeforeMethods('setUpKafkaAsyncPublishing')]
    public function bench_kafka_async_publishing(): void
    {
        $this->publishMessagesThroughCommandHandler();
    }

    private function publishMessagesThroughCommandHandler(): void
    {
        $this->messaging->sendCommandWithRoutingKey('benchmark.publishOrders', self::AMOUNT_OF_PUBLISHED_MESSAGES);
    }

    private function bootstrapWithAmqp(bool $asyncPublishing): FlowTestSupport
    {
        $channelBuilder = AmqpBackedMessageChannelBuilder::create('benchmark_orders', queueName: uniqid('benchmark_orders_'));
        if ($asyncPublishing) {
            $channelBuilder = $channelBuilder->withAsyncPublishing();
        }

        return EcotoneLite::bootstrapFlowTesting(
            [OrderPublisherService::class],
            [
                new OrderPublisherService(),
                AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']),
                DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::AMQP_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    $channelBuilder,
                    DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function bootstrapWithKafka(bool $asyncPublishing): FlowTestSupport
    {
        $topicName = uniqid('benchmark_orders_');
        $channelBuilder = KafkaMessageChannelBuilder::create('benchmark_orders', topicName: $topicName, messageGroupId: $topicName);
        if ($asyncPublishing) {
            $channelBuilder = $channelBuilder->withAsyncPublishing();
        }

        return EcotoneLite::bootstrapFlowTesting(
            [OrderPublisherService::class],
            [
                new OrderPublisherService(),
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([getenv('KAFKA_DSN') ?: 'localhost:9094']),
                DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    $channelBuilder,
                    DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
