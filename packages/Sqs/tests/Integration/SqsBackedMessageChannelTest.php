<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Enqueue\Sqs\SqsConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Sqs\AbstractConnectionTest;
use Test\Ecotone\Sqs\Fixture\SqsConsumer\SqsAsyncConsumerExample;

/**
 * @internal
 */
final class SqsBackedMessageChannelTest extends AbstractConnectionTest
{
    public function test_sending_and_receiving_message()
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = 'some';

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            containerOrAvailableServices: [
                SqsConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    SqsBackedMessageChannelBuilder::create($queueName),
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
        $queueName = 'sqs';
        $messagePayload = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            classesToResolve: [SqsAsyncConsumerExample::class],
            containerOrAvailableServices: [
                new SqsAsyncConsumerExample(),
                SqsConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SqsBackedMessageChannelBuilder::create($queueName),
                ])
        );

        $ecotoneLite->getCommandBus()->sendWithRouting('sqs_consumer', $messagePayload);
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $executionPollingMetadata = ExecutionPollingMetadata::createWithDefaults()
            ->withHandledMessageLimit(1)
            ->withExecutionTimeLimitInMilliseconds(100)
        ;

        $ecotoneLite->run('sqs', $executionPollingMetadata);
        $this->assertEquals([$messagePayload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $ecotoneLite->run('sqs', $executionPollingMetadata);
        $this->assertEquals([$messagePayload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));
    }
}
