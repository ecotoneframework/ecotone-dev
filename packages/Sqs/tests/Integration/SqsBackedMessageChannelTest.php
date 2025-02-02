<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Aws\Sqs\Exception\SqsException;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Test\LoggerExample;
use Enqueue\Sqs\SqsConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Sqs\ConnectionTestCase;
use Test\Ecotone\Sqs\Fixture\AsynchronousHandler\OrderService;
use Test\Ecotone\Sqs\Fixture\SqsConsumer\SqsAsyncConsumerExample;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SqsBackedMessageChannelTest extends ConnectionTestCase
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

    public function test_failing_to_consume_due_to_connection_failure()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            containerOrAvailableServices: [
                new OrderService(),
                SqsConnectionFactory::class => new SqsConnectionFactory('sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localhost:1000&version=latest'),
                'logger' => $loggerExample,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withConnectionRetryTemplate(
                    RetryTemplateBuilder::exponentialBackoff(1, 3)->maxRetryAttempts(3)
                )
                ->withExtensionObjects([
                    SqsBackedMessageChannelBuilder::create('async'),
                ])
        );

        $wasFinallyRethrown = false;
        try {
            $ecotoneLite->run(
                'async',
                ExecutionPollingMetadata::createWithDefaults()
                    ->withHandledMessageLimit(1)
                    ->withExecutionTimeLimitInMilliseconds(3000)
                    ->withStopOnError(false)
            );
        } catch (SqsException) {
            $wasFinallyRethrown = true;
        }

        $this->assertTrue($wasFinallyRethrown, 'Connection exception was not propagated');
        $this->assertEquals(
            [
                'Message Consumer starting to consume messages',
                ConnectionException::connectionRetryMessage(1, 1),
                ConnectionException::connectionRetryMessage(2, 3),
                ConnectionException::connectionRetryMessage(3, 9),
            ],
            $loggerExample->getInfo()
        );
    }
}
