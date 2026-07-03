<?php

declare(strict_types=1);

namespace Monorepo\Benchmark;

use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Warmup(0), Revs(1), Iterations(10)]
class AsyncPublishingBenchmark
{
    private const AMOUNT_OF_PUBLISHED_MESSAGES = 1000;

    private const MESSAGE_PAYLOAD = 'benchmark order payload for async publishing comparison';

    private MessagePublisher $publisher;

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

    #[BeforeMethods('setUpAmqpSynchronousPublishing')]
    public function bench_amqp_synchronous_publishing(): void
    {
        for ($messageNumber = 0; $messageNumber < self::AMOUNT_OF_PUBLISHED_MESSAGES; $messageNumber++) {
            $this->publisher->send(self::MESSAGE_PAYLOAD);
        }
    }

    #[BeforeMethods('setUpAmqpAsyncPublishing')]
    public function bench_amqp_async_publishing(): void
    {
        $this->publishAsynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpAmqpAsyncPublishing')]
    public function bench_amqp_async_batch_publishing(): void
    {
        $this->publishAsynchronouslyAsBatch();
    }

    #[BeforeMethods('setUpKafkaSynchronousPublishing')]
    public function bench_kafka_synchronous_publishing(): void
    {
        for ($messageNumber = 0; $messageNumber < self::AMOUNT_OF_PUBLISHED_MESSAGES; $messageNumber++) {
            $this->publisher->send(self::MESSAGE_PAYLOAD);
        }
    }

    #[BeforeMethods('setUpKafkaAsyncPublishing')]
    public function bench_kafka_async_publishing(): void
    {
        $this->publishAsynchronouslyOneByOne();
    }

    #[BeforeMethods('setUpKafkaAsyncPublishing')]
    public function bench_kafka_async_batch_publishing(): void
    {
        $this->publishAsynchronouslyAsBatch();
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

    private function publishAsynchronouslyAsBatch(): void
    {
        $batch = BatchMessage::constructEmpty();
        for ($messageNumber = 0; $messageNumber < self::AMOUNT_OF_PUBLISHED_MESSAGES; $messageNumber++) {
            $batch = $batch->append(self::MESSAGE_PAYLOAD, ['contentType' => MediaType::TEXT_PLAIN]);
        }

        $this->publisher->asyncPublish($batch, MediaType::TEXT_PLAIN)->resolve();
    }

    private function warmUpPublisher(): void
    {
        $this->publisher->send(self::MESSAGE_PAYLOAD);
    }

    private function bootstrapAmqpPublisher(bool $asyncPublishing): MessagePublisher
    {
        $publisherConfiguration = AmqpMessagePublisherConfiguration::create()
            ->withAutoDeclareQueueOnSend(true)
            ->withDefaultRoutingKey(uniqid('benchmark_orders_'));
        if ($asyncPublishing) {
            $publisherConfiguration = $publisherConfiguration->withAsyncPublishing();
        }

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
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
