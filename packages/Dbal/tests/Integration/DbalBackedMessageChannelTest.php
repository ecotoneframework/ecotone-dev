<?php

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\ComponentTestBuilder;
use Ecotone\Test\LoggerExample;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\DbalContext;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\AsynchronousHandler\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class DbalBackedMessageChannelTest extends DbalMessagingTestCase
{
    public function test_sending_and_receiving_via_channel()
    {
        $channelName = Uuid::uuid4()->toString();

        $messaging = ComponentTestBuilder::create()
                ->withReference(DbalConnectionFactory::class, $this->getConnectionFactory())
                ->withChannel(
                    DbalBackedMessageChannelBuilder::create($channelName)
                        ->withReceiveTimeout(1)
                )
                ->build();

        $payload = 'some';
        $headerName = 'token';
        $messaging->getMessageChannel($channelName)->send(
            MessageBuilder::withPayload($payload)
                ->setHeader($headerName, 123)
                ->build()
        );

        $receivedMessage = $messaging->getMessageChannel($channelName)->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
        $this->assertEquals($payload, $receivedMessage->getPayload(), 'Payload of received is different that sent one');
        $this->assertEquals(123, $receivedMessage->getHeaders()->get($headerName));
    }

    public function test_sending_and_receiving_via_channel_manager_registry()
    {
        $channelName = Uuid::uuid4()->toString();

        /** @var PollableChannel $messageChannel */
        $messageChannel = ComponentTestBuilder::create()
            ->withReference('managerRegistry', $this->getConnectionFactory(true))
            ->withChannel(
                DbalBackedMessageChannelBuilder::create($channelName, 'managerRegistry')
                    ->withReceiveTimeout(1)
            )
            ->build()
            ->getMessageChannel($channelName);

        $payload = 'some';
        $headerName = 'token';
        $messageChannel->send(
            MessageBuilder::withPayload($payload)
                ->setHeader($headerName, 123)
                ->build()
        );

        $receivedMessage = $messageChannel->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
        $this->assertEquals($payload, $receivedMessage->getPayload(), 'Payload of received is different that sent one');
        $this->assertEquals(123, $receivedMessage->getHeaders()->get($headerName));
    }

    public function test_sending_and_receiving_using_already_defined_connection()
    {
        $channelName = Uuid::uuid4()->toString();

        /** @var PollableChannel $messageChannel */
        $messageChannel = $this->getComponentTestingWithConnection(true)
            ->withChannel(DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1))
            ->build()
            ->getMessageChannel($channelName);

        $payload = 'some';
        $headerName = 'token';
        $messageChannel->send(
            MessageBuilder::withPayload($payload)
                ->setHeader($headerName, 123)
                ->build()
        );

        $receivedMessage = $messageChannel->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
        $this->assertEquals($payload, $receivedMessage->getPayload(), 'Payload of received is different that sent one');
        $this->assertEquals(123, $receivedMessage->getHeaders()->get($headerName));
    }

    public function test_reconnecting_on_disconnected_channel()
    {
        $connectionFactory = $this->getConnectionFactory();
        /** @var PollableChannel $messageChannel */
        $messageChannel = $this->getComponentTestingWithConnection()
            ->withChannel(
                DbalBackedMessageChannelBuilder::create($queueName = Uuid::uuid4()->toString())
                    ->withReceiveTimeout(1)
            )
            ->build()
            ->getMessageChannel($queueName);

        /** @var DbalContext $dbalContext */
        $dbalContext = $connectionFactory->createContext();
        $dbalContext->getDbalConnection()->close();

        $messageChannel->send(MessageBuilder::withPayload('some')->build());
        $receivedMessage = $messageChannel->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
    }

    public function test_reconnecting_on_disconnected_channel_with_manager_registry()
    {
        $connectionFactory = $this->getConnectionFactory(true);
        /** @var PollableChannel $messageChannel */
        $messageChannel = $this->getComponentTestingWithConnection()
            ->withChannel(
                DbalBackedMessageChannelBuilder::create($channelName = Uuid::uuid4()->toString())
                    ->withReceiveTimeout(1)
            )
            ->build()
            ->getMessageChannel($channelName);

        /** @var DbalContext $dbalContext */
        $dbalContext = $connectionFactory->createContext();
        $dbalContext->getDbalConnection()->close();

        $messageChannel->send(MessageBuilder::withPayload('some')->build());
        $receivedMessage = $messageChannel->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
    }

    public function test_delaying_the_message()
    {
        /** @var PollableChannel $messageChannel */
        $messageChannel = $this->getComponentTestingWithConnection(true)
            ->withChannel(
                DbalBackedMessageChannelBuilder::create($channelName = Uuid::uuid4()->toString())
                    ->withReceiveTimeout(1)
            )
            ->build()
            ->getMessageChannel($channelName);

        $messageChannel->send(
            MessageBuilder::withPayload('some')
                ->setHeader(MessageHeaders::DELIVERY_DELAY, 2000)
                ->build()
        );

        $this->assertNull($messageChannel->receive());

        sleep(3);

        $this->assertNotNull($messageChannel->receive());
    }

    public function test_sending_message()
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = 'some';

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($queueName),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannelByName($queueName);

        $messageChannel->send(MessageBuilder::withPayload($messagePayload)->build());

        $this->assertEquals(
            'some',
            $messageChannel->receiveWithTimeout(1)->getPayload()
        );

        $this->assertNull($messageChannel->receiveWithTimeout(1));
    }

    public function test_failing_to_receive_message_when_not_declared_and_auto_declare_off()
    {
        $queueName = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($queueName)
                        ->withAutoDeclare(false),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannelByName($queueName);

        $this->expectException(TableNotFoundException::class);

        $messageChannel->receiveWithTimeout(1);
    }

    public function test_failing_to_consume_due_to_connection_failure()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            containerOrAvailableServices: [
                new OrderService(),
                DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => 'pgsql://ecotone:secret@localhost:1000/ecotone']),
                'logger' => $loggerExample,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withConnectionRetryTemplate(
                    RetryTemplateBuilder::exponentialBackoff(1, 3)->maxRetryAttempts(3)
                )
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create('async'),
                ])
        );

        $wasFinallyRethrown = false;
        try {
            $ecotoneLite->run(
                'async',
                ExecutionPollingMetadata::createWithDefaults()
                    ->withHandledMessageLimit(1)
                    ->withExecutionTimeLimitInMilliseconds(0)
                    ->withStopOnError(false)
            );
        } catch (\Doctrine\DBAL\Exception\ConnectionException) {
            $wasFinallyRethrown = true;
        }

        $this->assertTrue($wasFinallyRethrown, 'Connection exception was not propagated');
        $this->assertEquals(
            [
                ConnectionException::connectionRetryMessage(1, 1),
                ConnectionException::connectionRetryMessage(2, 3),
                ConnectionException::connectionRetryMessage(3, 9),
            ],
            $loggerExample->getInfo()
        );
    }
}
