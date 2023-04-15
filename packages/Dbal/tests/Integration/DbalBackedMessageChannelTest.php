<?php

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\DbalContext;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTest;

/**
 * @internal
 */
class DbalBackedMessageChannelTest extends DbalMessagingTest
{
    public function test_sending_and_receiving_via_channel()
    {
        $channelName = Uuid::uuid4()->toString();

        /** @var PollableChannel $messageChannel */
        $messageChannel = DbalBackedMessageChannelBuilder::create($channelName)
                            ->withReceiveTimeout(1)
                            ->build($this->getReferenceSearchServiceWithConnection());

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

    public function test_sending_and_receiving_via_channel_manager_registry()
    {
        $channelName = Uuid::uuid4()->toString();

        /** @var PollableChannel $messageChannel */
        $messageChannel = DbalBackedMessageChannelBuilder::create($channelName, 'managerRegistry')
            ->withReceiveTimeout(1)
            ->build(InMemoryReferenceSearchService::createWith([
                'managerRegistry' => $this->getConnectionFactory(true),
            ]));

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
        $messageChannel = DbalBackedMessageChannelBuilder::create($channelName)
            ->withReceiveTimeout(1)
            ->build(InMemoryReferenceSearchService::createWith([
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ]));

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
        $messageChannel = DbalBackedMessageChannelBuilder::create(Uuid::uuid4()->toString())
            ->withReceiveTimeout(1)
            ->build(InMemoryReferenceSearchService::createWith([
                DbalConnectionFactory::class => $connectionFactory,
            ]));

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
        $messageChannel = DbalBackedMessageChannelBuilder::create(Uuid::uuid4()->toString())
            ->withReceiveTimeout(1)
            ->build(InMemoryReferenceSearchService::createWith([
                DbalConnectionFactory::class => $connectionFactory,
            ]));

        /** @var DbalContext $dbalContext */
        $dbalContext = $connectionFactory->createContext();
        $dbalContext->getDbalConnection()->close();

        $messageChannel->send(MessageBuilder::withPayload('some')->build());
        $receivedMessage = $messageChannel->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
    }

    public function test_delaying_the_message()
    {
        $messageChannel = DbalBackedMessageChannelBuilder::create(Uuid::uuid4()->toString())
            ->withReceiveTimeout(1)
            ->build(InMemoryReferenceSearchService::createWith([
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ]));

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

    public function test_failing_to_receive_message_when_not_declared()
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

        /** Dbal handle not declared queues as long as database table is created first */
        $this->expectException(ConnectionException::class);

        $messageChannel->receiveWithTimeout(1);
    }
}
