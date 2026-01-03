<?php

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\ClockSensitiveTrait;
use Ecotone\Test\StubLogger;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\DbalContext;
use Psr\Clock\ClockInterface;
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
    use ClockSensitiveTrait;

    public function test_sending_and_receiving_via_channel()
    {
        $channelName = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($channelName)
                        ->withReceiveTimeout(1),
                ])
        );

        $payload = 'some';
        $headerName = 'token';
        $messageChannel = $ecotoneLite->getMessageChannel($channelName);
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

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                'managerRegistry' => $this->getConnectionFactory(true),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($channelName, 'managerRegistry')
                        ->withReceiveTimeout(1),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannel($channelName);

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

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannel($channelName);

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
        $queueName = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $connectionFactory,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($queueName)
                        ->withReceiveTimeout(1),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannel($queueName);

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
        $channelName = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $connectionFactory,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($channelName)
                        ->withReceiveTimeout(1),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannel($channelName);

        /** @var DbalContext $dbalContext */
        $dbalContext = $connectionFactory->createContext();
        $dbalContext->getDbalConnection()->close();

        $messageChannel->send(MessageBuilder::withPayload('some')->build());
        $receivedMessage = $messageChannel->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
    }

    public function test_delaying_the_message()
    {
        $channelName = Uuid::uuid4()->toString();
        $clock = new StubUTCClock();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
                ClockInterface::class => $clock,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($channelName)
                        ->withReceiveTimeout(1),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannel($channelName);

        Clock::set($clock);

        $messageChannel->send(
            MessageBuilder::withPayload('some')
                ->setHeader(MessageHeaders::DELIVERY_DELAY, 2000)
                ->build()
        );

        $this->assertNull($messageChannel->receive());

        $clock->sleep(Duration::seconds(3));

        $this->assertNotNull($messageChannel->receive());
    }

    public function test_sending_message()
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = 'some';

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
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
        $messageChannel = $ecotoneLite->getMessageChannel($queueName);

        $messageChannel->send(MessageBuilder::withPayload($messagePayload)->build());

        $this->assertEquals(
            'some',
            $messageChannel->receiveWithTimeout(PollingMetadata::create('test')->setExecutionTimeLimitInMilliseconds(1))->getPayload()
        );

        $this->assertNull($messageChannel->receiveWithTimeout(PollingMetadata::create('test')->setExecutionTimeLimitInMilliseconds(1)));
    }

    public function test_failing_to_receive_message_when_not_declared_and_auto_declare_off()
    {
        $queueName = Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
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
        $messageChannel = $ecotoneLite->getMessageChannel($queueName);

        $this->expectException(TableNotFoundException::class);

        $messageChannel->receiveWithTimeout(PollingMetadata::create('test')->setExecutionTimeLimitInMilliseconds(1));
    }

    public function test_failing_to_consume_due_to_connection_failure()
    {
        $loggerExample = StubLogger::create();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
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
                'Message Consumer starting to consume messages',
                ConnectionException::connectionRetryMessage(1, 1),
                ConnectionException::connectionRetryMessage(2, 3),
                ConnectionException::connectionRetryMessage(3, 9),
            ],
            $loggerExample->getInfo()
        );
    }
}
