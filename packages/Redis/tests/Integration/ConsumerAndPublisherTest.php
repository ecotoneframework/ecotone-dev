<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Redis\Configuration\RedisMessageConsumerConfiguration;
use Ecotone\Redis\Configuration\RedisMessagePublisherConfiguration;
use Enqueue\Redis\RedisConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Redis\AbstractConnectionTest;
use Test\Ecotone\Redis\Fixture\RedisConsumer\RedisConsumerExample;

/**
 * @internal
 */
final class ConsumerAndPublisherTest extends AbstractConnectionTest
{
    public function testing_sending_message_using_publisher_and_receiving_using_consumer(): void
    {
        $endpointId = 'redis_consumer';
        $queueName = Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [RedisConsumerExample::class],
            [
                new RedisConsumerExample(),
                RedisConnectionFactory::class => $this->getConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE]))
                ->withExtensionObjects([
                    RedisMessageConsumerConfiguration::create($endpointId, $queueName),
                    RedisMessagePublisherConfiguration::create(queueName: $queueName),
                ])
        );

        $payload = Uuid::uuid4()->toString();
        $messagePublisher = $ecotoneLite->getMessagePublisher();
        $messagePublisher->send($payload);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));
    }
}
