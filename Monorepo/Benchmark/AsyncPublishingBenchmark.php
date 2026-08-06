<?php

declare(strict_types=1);

namespace Monorepo\Benchmark;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Dbal\Configuration\DbalMessagePublisherConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Redis\Configuration\RedisMessagePublisherConfiguration;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Sqs\Configuration\SqsMessagePublisherConfiguration;
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
 * Compares publishing scenarios per provider:
 * single message synchronous | single message asynchronous | batch message synchronous | batch message asynchronous
 * | multiple batches synchronous | multiple batches asynchronous (fire all batches first, await all confirmations once).
 *
 * DBAL and Redis confirm deliveries synchronously as part of the send call itself (database statement result
 * and command reply respectively), so the asynchronous scenarios are not supported for them and are not benchmarked.
 */
#[Warmup(0), Revs(1), Iterations(10)]
class AsyncPublishingBenchmark
{
    private const AMOUNT_OF_PUBLISHED_MESSAGES = 1000;

    private const AMOUNT_OF_BATCHES = 10;

    private const MESSAGES_PER_BATCH = 100;

    private const MESSAGE_PAYLOAD = 'benchmark order payload for async publishing comparison';

    private MessagePublisher $publisher;

    private MessageChannel $batchChannel;

    public function setUpAmqpSynchronousPublishing(): void
    {
        $this->publisher = $this->bootstrapAmqpPublisher(asyncPublishing: false);
        $this->warmUpPublisher();
    }

    public function setUpAmqpAsyncPublishing(): void
    {
        $this->publisher = $this->bootstrapAmqpPublisher(asyncPublishing: true);
        $this->warmUpPublisher();
    }

    public function setUpAmqpBatchChannel(): void
    {
        $this->batchChannel = $this->bootstrapBatchChannel(
            ModulePackageList::AMQP_PACKAGE,
            AmqpBackedMessageChannelBuilder::create(uniqid('benchmark_orders_'))->withHighThroughputPublishing(),
            [AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f'])],
        );
        $this->warmUpBatchChannel();
    }

    public function setUpKafkaSynchronousPublishing(): void
    {
        $this->publisher = $this->bootstrapKafkaPublisher(asyncPublishing: false);
        $this->warmUpPublisher();
    }

    public function setUpKafkaAsyncPublishing(): void
    {
        $this->publisher = $this->bootstrapKafkaPublisher(asyncPublishing: true);
        $this->warmUpPublisher();
    }

    public function setUpKafkaBatchChannel(): void
    {
        $uniqueId = uniqid('benchmark_orders_');
        $this->batchChannel = $this->bootstrapBatchChannel(
            ModulePackageList::KAFKA_PACKAGE,
            KafkaMessageChannelBuilder::create($uniqueId, topicName: $uniqueId, messageGroupId: $uniqueId)->withHighThroughputPublishing(),
            [KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([getenv('KAFKA_DSN') ?: 'localhost:9094'])],
        );
        $this->warmUpBatchChannel();
    }

    public function setUpDbalSynchronousPublishing(): void
    {
        $this->publisher = $this->bootstrapDbalPublisher();
        $this->warmUpPublisher();
    }

    public function setUpDbalBatchChannel(): void
    {
        $this->batchChannel = $this->bootstrapBatchChannel(
            ModulePackageList::DBAL_PACKAGE,
            DbalBackedMessageChannelBuilder::create(uniqid('benchmark_orders_'))->withHighThroughputPublishing(),
            [DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone')],
        );
        $this->warmUpBatchChannel();
    }

    public function setUpRedisSynchronousPublishing(): void
    {
        $this->publisher = $this->bootstrapRedisPublisher();
        $this->warmUpPublisher();
    }

    public function setUpRedisBatchChannel(): void
    {
        $this->batchChannel = $this->bootstrapBatchChannel(
            ModulePackageList::REDIS_PACKAGE,
            RedisBackedMessageChannelBuilder::create(uniqid('benchmark_orders_'))->withHighThroughputPublishing(),
            [RedisConnectionFactory::class => new RedisConnectionFactory(getenv('REDIS_DSN') ?: 'redis://localhost:6379')],
        );
        $this->warmUpBatchChannel();
    }

    public function setUpSqsSynchronousPublishing(): void
    {
        $this->publisher = $this->bootstrapSqsPublisher(asyncPublishing: false);
        $this->warmUpPublisher();
    }

    public function setUpSqsAsyncPublishing(): void
    {
        $this->publisher = $this->bootstrapSqsPublisher(asyncPublishing: true);
        $this->warmUpPublisher();
    }

    public function setUpSqsBatchChannel(): void
    {
        $this->batchChannel = $this->bootstrapBatchChannel(
            ModulePackageList::SQS_PACKAGE,
            SqsBackedMessageChannelBuilder::create(uniqid('benchmark_orders_'))->withHighThroughputPublishing(),
            [SqsConnectionFactory::class => new SqsConnectionFactory(getenv('SQS_DSN') ?: 'sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localhost:4566&version=latest')],
        );
        $this->warmUpBatchChannel();
    }

    #[BeforeMethods('setUpAmqpSynchronousPublishing')]
    public function bench_amqp_single_message_synchronous(): void
    {
        $this->publishSynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpAmqpAsyncPublishing')]
    public function bench_amqp_single_message_asynchronous(): void
    {
        $this->publishAsynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpAmqpBatchChannel')]
    public function bench_amqp_batch_message_synchronous(): void
    {
        $this->publishBatchSynchronously();
    }

    #[BeforeMethods('setUpAmqpAsyncPublishing')]
    public function bench_amqp_batch_message_asynchronous(): void
    {
        $this->publishBatchAsynchronously();
    }

    #[BeforeMethods('setUpAmqpBatchChannel')]
    public function bench_amqp_multiple_batches_synchronous(): void
    {
        $this->publishMultipleBatchesSynchronously();
    }

    #[BeforeMethods('setUpAmqpAsyncPublishing')]
    public function bench_amqp_multiple_batches_asynchronous(): void
    {
        $this->publishMultipleBatchesAsynchronously();
    }

    #[BeforeMethods('setUpKafkaSynchronousPublishing')]
    public function bench_kafka_single_message_synchronous(): void
    {
        $this->publishSynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpKafkaAsyncPublishing')]
    public function bench_kafka_single_message_asynchronous(): void
    {
        $this->publishAsynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpKafkaBatchChannel')]
    public function bench_kafka_batch_message_synchronous(): void
    {
        $this->publishBatchSynchronously();
    }

    #[BeforeMethods('setUpKafkaAsyncPublishing')]
    public function bench_kafka_batch_message_asynchronous(): void
    {
        $this->publishBatchAsynchronously();
    }

    #[BeforeMethods('setUpKafkaBatchChannel')]
    public function bench_kafka_multiple_batches_synchronous(): void
    {
        $this->publishMultipleBatchesSynchronously();
    }

    #[BeforeMethods('setUpKafkaAsyncPublishing')]
    public function bench_kafka_multiple_batches_asynchronous(): void
    {
        $this->publishMultipleBatchesAsynchronously();
    }

    #[BeforeMethods('setUpDbalSynchronousPublishing')]
    public function bench_dbal_single_message_synchronous(): void
    {
        $this->publishSynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpDbalBatchChannel')]
    public function bench_dbal_batch_message_synchronous(): void
    {
        $this->publishBatchSynchronously();
    }

    #[BeforeMethods('setUpRedisSynchronousPublishing')]
    public function bench_redis_single_message_synchronous(): void
    {
        $this->publishSynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpRedisBatchChannel')]
    public function bench_redis_batch_message_synchronous(): void
    {
        $this->publishBatchSynchronously();
    }

    #[BeforeMethods('setUpSqsSynchronousPublishing')]
    public function bench_sqs_single_message_synchronous(): void
    {
        $this->publishSynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpSqsAsyncPublishing')]
    public function bench_sqs_single_message_asynchronous(): void
    {
        $this->publishAsynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpSqsBatchChannel')]
    public function bench_sqs_batch_message_synchronous(): void
    {
        $this->publishBatchSynchronously();
    }

    #[BeforeMethods('setUpSqsAsyncPublishing')]
    public function bench_sqs_batch_message_asynchronous(): void
    {
        $this->publishBatchAsynchronously();
    }

    #[BeforeMethods('setUpSqsBatchChannel')]
    public function bench_sqs_multiple_batches_synchronous(): void
    {
        $this->publishMultipleBatchesSynchronously();
    }

    #[BeforeMethods('setUpSqsAsyncPublishing')]
    public function bench_sqs_multiple_batches_asynchronous(): void
    {
        $this->publishMultipleBatchesAsynchronously();
    }

    private function publishSynchronouslyOneByOne(): void
    {
        for ($messageNumber = 0; $messageNumber < self::AMOUNT_OF_PUBLISHED_MESSAGES; $messageNumber++) {
            $this->publisher->send(self::MESSAGE_PAYLOAD);
        }
    }

    private function publishAsynchronouslyOneByOne(): void
    {
        $futures = [];
        for ($messageNumber = 0; $messageNumber < self::AMOUNT_OF_PUBLISHED_MESSAGES; $messageNumber++) {
            $futures[] = $this->publisher->asyncPublish(self::MESSAGE_PAYLOAD, MediaType::TEXT_PLAIN);
        }
        foreach ($futures as $future) {
            $future->resolve();
        }
    }

    private function publishBatchSynchronously(): void
    {
        $this->batchChannel->send(
            MessageBuilder::withPayload($this->buildBatch(self::AMOUNT_OF_PUBLISHED_MESSAGES))->build()
        );
    }

    private function publishBatchAsynchronously(): void
    {
        $this->publisher->asyncPublish($this->buildBatch(self::AMOUNT_OF_PUBLISHED_MESSAGES), MediaType::TEXT_PLAIN)->resolve();
    }

    private function publishMultipleBatchesSynchronously(): void
    {
        for ($batchNumber = 0; $batchNumber < self::AMOUNT_OF_BATCHES; $batchNumber++) {
            $this->batchChannel->send(
                MessageBuilder::withPayload($this->buildBatch(self::MESSAGES_PER_BATCH))->build()
            );
        }
    }

    private function publishMultipleBatchesAsynchronously(): void
    {
        $futures = [];
        for ($batchNumber = 0; $batchNumber < self::AMOUNT_OF_BATCHES; $batchNumber++) {
            $futures[] = $this->publisher->asyncPublish($this->buildBatch(self::MESSAGES_PER_BATCH), MediaType::TEXT_PLAIN);
        }
        foreach ($futures as $future) {
            $future->resolve();
        }
    }

    private function buildBatch(int $amountOfMessages): BatchMessage
    {
        $batch = BatchMessage::constructEmpty();
        for ($messageNumber = 0; $messageNumber < $amountOfMessages; $messageNumber++) {
            $batch = $batch->append(self::MESSAGE_PAYLOAD, ['contentType' => MediaType::TEXT_PLAIN]);
        }

        return $batch;
    }

    private function warmUpPublisher(): void
    {
        $this->publisher->send(self::MESSAGE_PAYLOAD);
    }

    private function warmUpBatchChannel(): void
    {
        $this->batchChannel->send(
            MessageBuilder::withPayload(self::MESSAGE_PAYLOAD)
                ->setContentType(MediaType::createTextPlain())
                ->build()
        );
    }

    private function bootstrapBatchChannel(string $modulePackage, object $channelBuilder, array $services): MessageChannel
    {
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            $services,
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, $modulePackage]))
                ->withExtensionObjects([$channelBuilder]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getMessageChannel($channelBuilder->getMessageChannelName());
    }

    private function bootstrapAmqpPublisher(bool $asyncPublishing): MessagePublisher
    {
        $queueName = uniqid('benchmark_orders_');
        $connectionFactory = new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $context = $connectionFactory->createContext();
        $context->declareQueue($context->createQueue($queueName));

        $publisherConfiguration = AmqpMessagePublisherConfiguration::create()
            ->withAutoDeclareQueueOnSend(true)
            ->withDefaultRoutingKey($queueName);
        if ($asyncPublishing) {
            $publisherConfiguration = $publisherConfiguration->withAsyncPublishing();
        }

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                AmqpConnectionFactory::class => $connectionFactory,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([$publisherConfiguration]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
    }

    private function bootstrapDbalPublisher(): MessagePublisher
    {
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([DbalMessagePublisherConfiguration::create(MessagePublisher::class, uniqid('benchmark_orders_'))]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
    }

    private function bootstrapRedisPublisher(): MessagePublisher
    {
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                RedisConnectionFactory::class => new RedisConnectionFactory(getenv('REDIS_DSN') ?: 'redis://localhost:6379'),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE]))
                ->withExtensionObjects([RedisMessagePublisherConfiguration::create(queueName: uniqid('benchmark_orders_'))]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
    }

    private function bootstrapSqsPublisher(bool $asyncPublishing): MessagePublisher
    {
        $publisherConfiguration = SqsMessagePublisherConfiguration::create(queueName: uniqid('benchmark_orders_'));
        if ($asyncPublishing) {
            $publisherConfiguration = $publisherConfiguration->withAsyncPublishing();
        }

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                SqsConnectionFactory::class => new SqsConnectionFactory(getenv('SQS_DSN') ?: 'sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localhost:4566&version=latest'),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([$publisherConfiguration]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
    }

    private function bootstrapKafkaPublisher(bool $asyncPublishing): MessagePublisher
    {
        $publisherConfiguration = KafkaPublisherConfiguration::createWithDefaults(topicName: uniqid('benchmark_orders_'));
        if ($asyncPublishing) {
            $publisherConfiguration = $publisherConfiguration->withAsyncPublishing();
        }

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([getenv('KAFKA_DSN') ?: 'localhost:9094']),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([$publisherConfiguration]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
    }
}
