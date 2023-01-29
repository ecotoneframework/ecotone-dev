<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Enqueue\Redis\RedisConnectionFactory;
use Enqueue\Redis\RedisDestination;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Redis\AbstractConnectionTest;
use Test\Ecotone\Redis\Fixture\RedisConsumer\RedisAsyncConsumerExample;

/**
 * @internal
 */
final class RedisBackedMessageChannelTest extends AbstractConnectionTest
{
    public function test_sending_and_receiving_message(): void
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            containerOrAvailableServices: [
                RedisConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE]))
                ->withExtensionObjects([
                    RedisBackedMessageChannelBuilder::create($queueName),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannelByName($queueName);

        $messageChannel->send(MessageBuilder::withPayload($messagePayload)->build());

        $this->assertEquals(
            $messagePayload,
            $messageChannel->receiveWithTimeout(1)->getPayload()
        );

        $this->assertNull($messageChannel->receiveWithTimeout(1));
    }

    public function test_sending_and_receiving_message_from_using_asynchronous_command_handler(): void
    {
        $queueName = 'redis';
        $messagePayload = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            classesToResolve: [RedisAsyncConsumerExample::class],
            containerOrAvailableServices: [
                new RedisAsyncConsumerExample(),
                RedisConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    RedisBackedMessageChannelBuilder::create($queueName),
                ])
        );

        $this->getConnectionFactory()->createContext()->purgeQueue(new RedisDestination($queueName));

        $ecotoneLite->getCommandBus()->sendWithRouting('redis_consumer', $messagePayload);
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $executionPollingMetadata = ExecutionPollingMetadata::createWithDefaults()
            ->withHandledMessageLimit(1)
            ->withExecutionTimeLimitInMilliseconds(100)
        ;

        $ecotoneLite->run('redis', $executionPollingMetadata);
        $this->assertEquals([$messagePayload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $ecotoneLite->run('redis', $executionPollingMetadata);
        $this->assertEquals([$messagePayload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));
    }
}
