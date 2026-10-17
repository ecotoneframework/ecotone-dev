<?php

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Messaging\PollableChannel;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Test\ChannelTestSupport;
use Ecotone\Test\StaticMessageBuilder;
use Ecotone\Test\StubLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
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
        $channelName = Uuid::v7()->toRfc4122();

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        $payload = 'some';
        $headerName = 'token';
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($channelName);
        $messageChannel->send(
            StaticMessageBuilder::withPayload($payload)
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
        $channelName = Uuid::v7()->toRfc4122();

        (new EnqueueTableManager(EnqueueTableManager::DEFAULT_TABLE_NAME, true))->createTable($this->getConnection());

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                'managerRegistry' => $this->getConnectionFactory(true),
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($channelName, 'managerRegistry')->withReceiveTimeout(1)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($channelName);

        $payload = 'some';
        $headerName = 'token';
        $messageChannel->send(
            StaticMessageBuilder::withPayload($payload)
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
        $channelName = Uuid::v7()->toRfc4122();

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($channelName);

        $payload = 'some';
        $headerName = 'token';
        $messageChannel->send(
            StaticMessageBuilder::withPayload($payload)
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
        $queueName = Uuid::v7()->toRfc4122();

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $connectionFactory,
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($queueName)->withReceiveTimeout(1)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($queueName);

        /** @var DbalContext $dbalContext */
        $dbalContext = $connectionFactory->createContext();
        $dbalContext->getDbalConnection()->close();

        $messageChannel->send(StaticMessageBuilder::withPayload('some')->build());
        $receivedMessage = $messageChannel->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
    }

    public function test_reconnecting_on_disconnected_channel_with_manager_registry()
    {
        $connectionFactory = $this->getConnectionFactory(true);
        $channelName = Uuid::v7()->toRfc4122();

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $connectionFactory,
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($channelName);

        /** @var DbalContext $dbalContext */
        $dbalContext = $connectionFactory->createContext();
        $dbalContext->getDbalConnection()->close();

        $messageChannel->send(StaticMessageBuilder::withPayload('some')->build());
        $receivedMessage = $messageChannel->receive();

        $this->assertNotNull($receivedMessage, 'Not received message');
    }

    public function test_delaying_the_message_with_custom_clock()
    {
        $channelName = Uuid::v7()->toRfc4122();
        $clock = new StubUTCClock();

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
                ClockInterface::class => $clock,
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($channelName);

        $messageChannel->send(
            StaticMessageBuilder::withPayload('some')
                ->setHeader(MessageHeaders::DELIVERY_DELAY, 2000)
                ->build()
        );

        $this->assertNull($messageChannel->receive());

        $clock->sleep(Duration::seconds(3));

        $this->assertNotNull($messageChannel->receive());
    }

    public function test_delaying_the_message_with_native_clock()
    {
        $channelName = Uuid::v7()->toRfc4122();

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($channelName);

        $messageChannel->send(
            StaticMessageBuilder::withPayload('some')
                ->setHeader(MessageHeaders::DELIVERY_DELAY, 2000)
                ->build()
        );

        $ecotoneLite->advanceTimeBy(Duration::seconds(1));

        $this->assertNull($messageChannel->receive());

        $ecotoneLite->advanceTimeBy(Duration::seconds(3));

        $this->assertNotNull($messageChannel->receive());
    }

    public function test_delaying_the_message_with_native_clock_using_date_time()
    {
        $channelName = Uuid::v7()->toRfc4122();

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($channelName);

        $messageChannel->send(
            StaticMessageBuilder::withPayload('some')
                ->setHeader(MessageHeaders::DELIVERY_DELAY, 2000)
                ->build()
        );

        /** @var EcotoneClockInterface $clock */
        $clock = $ecotoneLite->getServiceFromContainer(EcotoneClockInterface::class);
        $ecotoneLite->changeTimeTo($clock->now()->add(Duration::seconds(1)));

        $this->assertNull($messageChannel->receive());

        $ecotoneLite->changeTimeTo($clock->now()->add(Duration::seconds(3)));

        $this->assertNotNull($messageChannel->receive());
    }

    public function test_sending_message()
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messagePayload = 'some';

        $ecotoneLite = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($queueName)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = ChannelTestSupport::of($ecotoneLite)->getMessageChannel($queueName);

        $messageChannel->send(StaticMessageBuilder::withPayload($messagePayload)->build());

        $this->assertEquals(
            'some',
            $messageChannel->receiveWithTimeout(PollingMetadata::create('test')->withExecutionTimeLimitInMilliseconds(1))->getPayload()
        );

        $this->assertNull($messageChannel->receiveWithTimeout(PollingMetadata::create('test')->withExecutionTimeLimitInMilliseconds(1)));
    }

    public function test_receiving_when_not_declared_and_auto_declare_off_names_the_message_queue_setup_command()
    {
        $messageChannel = $this->channelWithoutAutoDeclare();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EnqueueTableManager::FEATURE_NAME, EnqueueTableManager::DEFAULT_TABLE_NAME, null));

        $messageChannel->receiveWithTimeout(PollingMetadata::create('test')->withExecutionTimeLimitInMilliseconds(1));
    }

    public function test_sending_when_not_declared_and_auto_declare_off_names_the_message_queue_setup_command()
    {
        $messageChannel = $this->channelWithoutAutoDeclare();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EnqueueTableManager::FEATURE_NAME, EnqueueTableManager::DEFAULT_TABLE_NAME, null));

        $messageChannel->send(StaticMessageBuilder::withPayload('some')->build());
    }

    private function channelWithoutAutoDeclare(): PollableChannel
    {
        $queueName = Uuid::v7()->toRfc4122();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create($queueName)->withAutoDeclare(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );

        return ChannelTestSupport::of($ecotoneLite)->getMessageChannel($queueName);
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
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create('async'),
                ServiceConfiguration::createWithDefaults()->withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackOff(1, 3)->maxRetries(3)),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
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
            array_values(array_filter(
                $loggerExample->getInfo(),
                fn ($logEntry) => ! is_string($logEntry) || ! str_starts_with($logEntry, 'Executing Message Handler')
            ))
        );
    }
}
