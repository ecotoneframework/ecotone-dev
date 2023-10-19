<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpAdmin;
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\AmqpBinding;
use Ecotone\Amqp\AmqpExchange;
use Ecotone\Amqp\AmqpHeader;
use Ecotone\Amqp\AmqpInboundChannelAdapterBuilder;
use Ecotone\Amqp\AmqpOutboundChannelAdapterBuilder;
use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\Configuration\AmqpMessageConsumerConfiguration;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Enqueue\EnqueueMessageChannel;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Config\InMemoryChannelResolver;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\ConversionException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Conversion\ObjectToSerialized\SerializingConverter;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\EndpointRunner;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\ComponentTestBuilder;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Exception;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use stdClass;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Amqp\Fixture\AmqpConsumer\AmqpConsumerExample;
use Test\Ecotone\Amqp\Fixture\Handler\ExceptionalMessageHandler;

/**
 * Class InboundAmqpGatewayBuilder
 * @package Test\Amqp
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class AmqpChannelAdapterTest extends AmqpMessagingTest
{
    private ComponentTestBuilder $componentTest;

    public function setUp(): void
    {
        parent::setUp();
        $this->componentTest = ComponentTestBuilder::create();
    }

    /**
     * @throws MessagingException
     */
    public function test_sending_to_default_exchange_with_routing_by_queue_name()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($queueName),
        ];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $messageToSend = MessageBuilder::withPayload('some')->build();
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $messageToSend);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);
        $message = $this->receiveOnce($inboundAmqpAdapter, $inboundRequestChannel);

        $this->assertNotNull($message, 'Message was not received from rabbit');

        $this->assertEquals(
            $message->getPayload(),
            'some'
        );
    }

    public function test_throwing_exception_and_requeuing_when_stop_on_error_is_defined()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($queueName),
        ];
        $amqpExchanges = [];
        $amqpBindings = [];
        $exceptionChannelName = 'exceptionChannel';
        $successChannelName = 'successChannel';
        $channelWithException = DirectChannel::create();
        $channelWithException->subscribe(ExceptionalMessageHandler::create());
        $successChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $messageToSend = MessageBuilder::withPayload('some')->build();
        $converters = [];
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);
        $this->componentTest
            ->withChannel($exceptionChannelName, $channelWithException)
            ->withChannel('successChannel', $successChannel);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $messageToSend);

        $this->expectException(RuntimeException::class);

        $this->buildConsumerRunner(
            $this->createAmqpInboundAdapter($queueName, $exceptionChannelName, $amqpConnectionReferenceName, 'some-id'),
            PollingMetadata::create('some-id')
                ->setStopOnError(true)
                ->setExecutionAmountLimit(1000)
        )
            ->runEndpointWithExecutionPollingMetadata();

        $this->buildConsumerRunner(
            $this->createAmqpInboundAdapter($queueName, $successChannelName, $amqpConnectionReferenceName, 'endpoint.2'),
            PollingMetadata::create('')
                ->setStopOnError(true)
                ->setExecutionAmountLimit(1000)
        )
            ->runEndpointWithExecutionPollingMetadata();

        $this->assertNotNull($successChannel->receiveWithTimeout(1000));
    }

    public function test_throwing_exception_and_rejecting_when_stop_on_error_is_defined_with_error_channel()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($queueName),
        ];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = DirectChannel::create();
        $inboundRequestChannel->subscribe(ExceptionalMessageHandler::create());
        $amqpConnectionReferenceName = 'connection';
        $messageToSend = MessageBuilder::withPayload('some')->build();
        $converters = [];
        $errorChannel = QueueChannel::create();

        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createChannelResolver('errorChannel', $errorChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $messageToSend);

        $this->expectException(RuntimeException::class);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName, 'some-id');
        $inboundAmqpGateway = $this->buildConsumerRunner($inboundAmqpAdapter, PollingMetadata::create('some-id')
            ->setErrorChannelName('errorChannel')
            ->setStopOnError(true)
            ->setExecutionAmountLimit(1000));

        $inboundAmqpGateway->runEndpointWithExecutionPollingMetadata();

        $this->assertNull($errorChannel->receive());
    }

    /**
     * @param string $requestChannelName
     * @param MessageChannel $inboundRequestChannel
     *
     * @return InMemoryChannelResolver
     * @throws MessagingException
     */
    private function createChannelResolver(string $requestChannelName, MessageChannel $inboundRequestChannel): void
    {
        $this->componentTest->withChannel($requestChannelName, $inboundRequestChannel);
    }

    /**
     * @param string $amqpConnectionReferenceName
     * @param array $amqpExchanges
     * @param array $amqpQueues
     * @param array $amqpBindings
     * @param array $converters
     *
     * @throws MessagingException
     */
    private function createReferenceSearchService(string $amqpConnectionReferenceName, array $amqpExchanges, array $amqpQueues, array $amqpBindings, array $converters): void
    {
        $this->componentTest
            ->withReference($amqpConnectionReferenceName, $this->getCachedConnectionFactory())
            ->withReference(AmqpAdmin::REFERENCE_NAME, AmqpAdmin::createWith($amqpExchanges, $amqpQueues, $amqpBindings))
            ->withReference(ConversionService::REFERENCE_NAME, AutoCollectionConversionService::createWith($converters));
        ;
    }

    /**
     * @param AmqpOutboundChannelAdapterBuilder $outboundAmqpGatewayBuilder
     * @param ChannelResolver $channelResolver
     * @param ReferenceSearchService $referenceSearchService
     * @param Message $messageToSend
     *
     * @return void
     * @throws Exception
     */
    private function send(AmqpOutboundChannelAdapterBuilder $outboundAmqpGatewayBuilder, Message $messageToSend)
    {
        $this->componentTest
            ->build(
                $outboundAmqpGatewayBuilder
                    ->withAutoDeclareOnSend(true)
            )
            ->handle($messageToSend);
    }

    /**
     * @param string $queueName
     * @param string $requestChannelName
     * @param string $amqpConnectionReferenceName
     *
     * @return AmqpInboundChannelAdapterBuilder
     * @throws Exception
     */
    private function createAmqpInboundAdapter(string $queueName, string $requestChannelName, string $amqpConnectionReferenceName, string $endpointId = 'some-id'): AmqpInboundChannelAdapterBuilder
    {
        return AmqpInboundChannelAdapterBuilder::createWith(
            $endpointId,
            $queueName,
            $requestChannelName,
            $amqpConnectionReferenceName
        )
            ->withReceiveTimeout(1);
    }

    /**
     * @param AmqpInboundChannelAdapterBuilder $inboundAmqpGatewayBuilder
     * @param QueueChannel $inboundRequestChannel
     * @param ChannelResolver $channelResolver
     * @param ReferenceSearchService $referenceSearchService
     *
     * @return Message|null
     */
    private function receiveOnce(AmqpInboundChannelAdapterBuilder $inboundAmqpGatewayBuilder, QueueChannel $inboundRequestChannel): ?Message
    {
        return $this->receiveWithPollingMetadata($inboundAmqpGatewayBuilder, $inboundRequestChannel, PollingMetadata::create($inboundAmqpGatewayBuilder->getEndpointId())->setExecutionAmountLimit(100)->setExecutionTimeLimitInMilliseconds(100));
    }

    /**
     * @param AmqpInboundChannelAdapterBuilder $inboundAmqpGatewayBuilder
     * @param QueueChannel $inboundRequestChannel
     * @param ChannelResolver $channelResolver
     * @param ReferenceSearchService $referenceSearchService
     *
     * @param PollingMetadata $pollingMetadata
     * @return Message|null
     */
    private function receiveWithPollingMetadata(AmqpInboundChannelAdapterBuilder $inboundAmqpGatewayBuilder, MessageChannel $inboundRequestChannel, PollingMetadata $pollingMetadata): ?Message
    {
        $consumerRunner = $this->buildConsumerRunner($inboundAmqpGatewayBuilder, $pollingMetadata);
        $consumerRunner->runEndpointWithExecutionPollingMetadata();

        return $inboundRequestChannel->receive();
    }

    private function buildConsumerRunner(AmqpInboundChannelAdapterBuilder $inboundAmqpGatewayBuilder, PollingMetadata $pollingMetadata): EndpointRunner
    {
        return $this->componentTest
            ->withPollingMetadata($pollingMetadata)
            ->withRegisteredChannelAdapter($inboundAmqpGatewayBuilder)
            ->getEndpointRunner($inboundAmqpGatewayBuilder->getEndpointId());
    }

    /**
     * @throws MessagingException
     */
    public function test_throwing_exception_if_sending_non_string_payload_without_media_type_information()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [AmqpQueue::createWith($queueName)];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $messageToSend = MessageBuilder::withPayload(new stdClass())->build();
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName);

        $this->expectException(ConversionException::class);

        $this->send($outboundAmqpGatewayBuilder, $messageToSend);
    }

    /**
     * @throws MessagingException
     */
    public function test_throwing_exception_if_sending_non_string_payload_with_media_type_but_no_converter_available()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [AmqpQueue::createWith($queueName)];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $messageToSend = MessageBuilder::withPayload(new stdClass())
            ->setContentType(MediaType::createApplicationXPHP())
            ->build();
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName);

        $this->expectException(ConversionException::class);

        $this->send($outboundAmqpGatewayBuilder, $messageToSend);
    }

    /**
     * @throws MessagingException
     */
    public function test_converting_payload_to_string_if_converter_exists_and_media_type_passed()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [AmqpQueue::createWith($queueName)];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $payload = new stdClass();
        $payload->name = 'someName';
        $messageToSend = MessageBuilder::withPayload($payload)
            ->setContentType(MediaType::createApplicationXPHP())
            ->build();
        $converters = [new SerializingConverter()];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);

        $this->send($outboundAmqpGatewayBuilder, $messageToSend);


        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);
        $message = $this->receiveOnce($inboundAmqpAdapter, $inboundRequestChannel);

        $this->assertNotNull($message, 'Message was not received from rabbit');
        $this->assertEquals(
            $message->getPayload(),
            addslashes(serialize($payload))
        );
    }

    /**
     * @throws MessagingException
     */
    public function test_not_receiving_a_message_when_queue_is_empty()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($queueName),
        ];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $inboundAmqpGatewayBuilder = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);

        $this->assertNull($this->receiveOnce($inboundAmqpGatewayBuilder, $inboundRequestChannel));
    }

    /**
     * @throws MessagingException
     */
    public function test_sending_and_receiving_with_routing_key_to_custom_exchange()
    {
        $exchangeName = Uuid::uuid4()->toString();
        $whiteQueueName = Uuid::uuid4()->toString();
        $blackQueueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($blackQueueName),
            AmqpQueue::createWith($whiteQueueName),
        ];
        $amqpExchanges = [
            AmqpExchange::createDirectExchange($exchangeName),
        ];
        $amqpBindings = [
            AmqpBinding::createFromNames($exchangeName, $whiteQueueName, 'white'),
            AmqpBinding::createFromNames($exchangeName, $blackQueueName, 'black'),
        ];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);


        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName, 'an-id');
        $inboundAmqpAdapterForWhite = $this->createAmqpInboundAdapter($whiteQueueName, $requestChannelName, $amqpConnectionReferenceName, 'an-other-id');

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create($exchangeName, $amqpConnectionReferenceName)
            ->withDefaultRoutingKey('white');
        $this->send($outboundAmqpGatewayBuilder, MessageBuilder::withPayload('some')->build());

        $this->assertNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel));
        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForWhite, $inboundRequestChannel));
    }

    /**
     * @throws MessagingException
     */
    public function test_sending_and_receiving_with_routing_key_in_message()
    {
        $blackQueueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($blackQueueName),
        ];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, [], $amqpQueues, [], $converters);


        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create('', $amqpConnectionReferenceName)
            ->withRoutingKeyFromHeader('routingKey');
        $this->send(
            $outboundAmqpGatewayBuilder,
            MessageBuilder::withPayload('some')
                ->setHeader('routingKey', $blackQueueName)
                ->build()
        );

        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel));
    }

    /**
     * @throws MessagingException
     */
    public function test_sending_and_receiving_with_exchange_in_message()
    {
        $exchangeName = Uuid::uuid4()->toString();
        $blackQueueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($blackQueueName),
        ];
        $amqpExchanges = [
            AmqpExchange::createFanoutExchange($exchangeName),
        ];
        $amqpBindings = [
            AmqpBinding::createFromNames($exchangeName, $blackQueueName, null),
        ];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create('', $amqpConnectionReferenceName)
            ->withExchangeFromHeader('exchangeKey');
        $this->send(
            $outboundAmqpGatewayBuilder,
            MessageBuilder::withPayload('some')
                ->setHeader('exchangeKey', $exchangeName)
                ->build()
        );

        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel));
    }

    /**
     * @throws MessagingException
     */
    public function test_sending_and_receiving_from_topic_exchange()
    {
        $exchangeName = Uuid::uuid4()->toString();
        $whiteQueueName = Uuid::uuid4()->toString();
        $blackQueueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($blackQueueName),
            AmqpQueue::createWith($whiteQueueName),
        ];
        $amqpExchanges = [
            AmqpExchange::createTopicExchange($exchangeName),
        ];
        $amqpBindings = [
            AmqpBinding::createFromNames($exchangeName, $whiteQueueName, '*.white'),
            AmqpBinding::createFromNames($exchangeName, $blackQueueName, '*.black'),
        ];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);


        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName, 'an-id');
        $inboundAmqpAdapterForWhite = $this->createAmqpInboundAdapter($whiteQueueName, $requestChannelName, $amqpConnectionReferenceName, 'an-other-id');

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create($exchangeName, $amqpConnectionReferenceName)
            ->withDefaultRoutingKey('color.white');
        $this->send($outboundAmqpGatewayBuilder, MessageBuilder::withPayload('some')->build());

        $this->assertNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel));
        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForWhite, $inboundRequestChannel));
    }

    /**
     * @throws MessagingException
     */
    public function test_sending_and_receiving_with_header_mapping()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($queueName),
        ];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $messageToSend = MessageBuilder::withPayload('some')
            ->setHeader('token', '123')
            ->setHeader('userId', 2)
            ->setHeader('userName', 'Johny')
            ->setHeader('userSurname', 'Casa')
            ->build();
        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withHeaderMapper('token,user*')
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $messageToSend);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName)
            ->withHeaderMapper('token, userName');
        $message = $this->receiveOnce($inboundAmqpAdapter, $inboundRequestChannel);

        $this->assertNotNull($message, 'Message was not received from rabbit');

        $this->assertEquals('123', $message->getHeaders()->get('token'));
        $this->assertEquals('Johny', $message->getHeaders()->get('userName'));
    }

    /**
     * @throws MessagingException
     */
    public function test_sending_message_with_auto_acking()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [AmqpQueue::createWith($queueName)];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = DirectChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $messageToSend = MessageBuilder::withPayload('some')->build();
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $messageToSend);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);

        $inboundQueueChannel = QueueChannel::create();
        $inboundRequestChannel->subscribe(ForwardMessageHandler::create($inboundQueueChannel));

        $inboundAmqpGateway = $this->buildConsumerRunner($inboundAmqpAdapter, PollingMetadata::create('some-id')
            ->setHandledMessageLimit(1)
            ->setExecutionAmountLimit(100)
            ->setExecutionTimeLimitInMilliseconds(100));
        $inboundAmqpGateway->runEndpointWithExecutionPollingMetadata();

        $inboundAmqpGateway->runEndpointWithExecutionPollingMetadata();

        $this->assertNotNull($inboundQueueChannel->receive(), 'Message was not requeued correctly');

        $inboundAmqpGateway->runEndpointWithExecutionPollingMetadata();

        $this->assertNull($inboundQueueChannel->receive(), 'Message was not acked correctly');
    }

    public function test_sending_with_time_to_live()
    {
        $queueName = Uuid::uuid4()->toString();
        $amqpQueues = [AmqpQueue::createWith($queueName)];
        $amqpExchanges = [];
        $amqpBindings = [];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = DirectChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $messageToSend = MessageBuilder::withPayload('some')->build();
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultTimeToLive(1)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $messageToSend);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);
        $inboundQueueChannel = QueueChannel::create();
        $inboundRequestChannel->subscribe(ForwardMessageHandler::create($inboundQueueChannel));

        $inboundAmqpGateway = $this->buildConsumerRunner($inboundAmqpAdapter, PollingMetadata::create('some-id')->setExecutionTimeLimitInMilliseconds(1000));

        usleep(1500);
        $inboundAmqpGateway->runEndpointWithExecutionPollingMetadata();

        $this->assertNull($inboundQueueChannel->receive(), 'Message was did no expire');
    }

    public function test_delaying_the_message()
    {
        $queueName = Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [],
            [
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create($queueName),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannelByName($queueName);
        $messageChannel->send(
            MessageBuilder::withPayload('some')
                ->setHeader(MessageHeaders::DELIVERY_DELAY, 250)
                ->build()
        );

        $this->assertNull($messageChannel->receiveWithTimeout(200));

        $this->assertNotNull($messageChannel->receiveWithTimeout(1000));
    }

    public function test_receiving_from_dead_letter_queue()
    {
        $normalQueueEndpointId = 'normal_queue';
        $deadLetterQueueEndpointId = 'asynchronous_endpoint';
        $queueName = Uuid::uuid4()->toString();
        $deadLetterQueueName = Uuid::uuid4()->toString();
        $deadLetterQueue = AmqpQueue::createWith($deadLetterQueueName);
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [ExceptionalMessageHandler::class, AmqpConsumerExample::class],
            [
                ExceptionalMessageHandler::createWithRejectException(),
                new AmqpConsumerExample(),
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessageConsumerConfiguration::create($normalQueueEndpointId, $queueName),
                    AmqpMessageConsumerConfiguration::create($deadLetterQueueEndpointId, $deadLetterQueueName),
                    AmqpQueue::createWith($queueName)
                        ->withDeadLetterForDefaultExchange($deadLetterQueue),
                    $deadLetterQueue,
                    AmqpMessagePublisherConfiguration::create()
                        ->withDefaultRoutingKey($queueName),
                ])
        );

        $payload = 'random_payload';
        $messagePublisher = $ecotoneLite->getMessagePublisher();
        $messagePublisher->send($payload);

        $ecotoneLite->run($normalQueueEndpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(200));
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $ecotoneLite->run($deadLetterQueueEndpointId, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        $this->assertEquals([$payload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));
    }

    /**
     * @throws MessagingException
     */
    public function test_receiving_message_second_time_when_requeued()
    {
        $queueName = Uuid::uuid4()->toString();

        $amqpBackedMessageChannel = $this->createDirectAmqpBackendMessageChannel($queueName);
        $amqpBackedMessageChannel->send(MessageBuilder::withPayload('some')->build());

        /** @var Message $message */
        $message = $amqpBackedMessageChannel->receiveWithTimeout(1000);

        /** @var AcknowledgementCallback $acknowledgeCallback */
        $acknowledgeCallback = $message->getHeaders()->get(AmqpHeader::HEADER_ACKNOWLEDGE);
        $acknowledgeCallback->requeue();

        $this->assertNotNull($amqpBackedMessageChannel->receiveWithTimeout(1000));
    }

    public function test_receiving_message_second_time_with_different_timeouts_when_requeued()
    {
        $queueName = Uuid::uuid4()->toString();

        $amqpBackedMessageChannel = $this->createDirectAmqpBackendMessageChannel($queueName);
        $amqpBackedMessageChannel->send(MessageBuilder::withPayload('some')->build());

        /** @var Message $message */
        $message = $amqpBackedMessageChannel->receiveWithTimeout(1000);

        /** @var AcknowledgementCallback $acknowledgeCallback */
        $acknowledgeCallback = $message->getHeaders()->get(AmqpHeader::HEADER_ACKNOWLEDGE);
        $acknowledgeCallback->requeue();

        $this->assertNotNull($amqpBackedMessageChannel->receiveWithTimeout(1000));
    }


    /**
     * @throws MessagingException
     */
    public function test_not_receiving_message_second_time_when_acked()
    {
        $queueName = Uuid::uuid4()->toString();

        $amqpBackedMessageChannel = $this->createDirectAmqpBackendMessageChannel($queueName);
        $amqpBackedMessageChannel->send(MessageBuilder::withPayload('some')->build());

        /** @var Message $message */
        $message = $amqpBackedMessageChannel->receiveWithTimeout(1000);
        $this->acceptMessage($message);

        $this->assertNull($amqpBackedMessageChannel->receive());
    }

    /**
     * @throws MessagingException
     */
    public function test_not_receiving_message_second_time_when_rejected()
    {
        $queueName = Uuid::uuid4()->toString();

        $amqpBackedMessageChannel = $this->createDirectAmqpBackendMessageChannel($queueName);
        $amqpBackedMessageChannel->send(MessageBuilder::withPayload('some')->build());

        /** @var Message $message */
        $message = $amqpBackedMessageChannel->receiveWithTimeout(1000);

        /** @var AcknowledgementCallback $acknowledgeCallback */
        $acknowledgeCallback = $message->getHeaders()->get(AmqpHeader::HEADER_ACKNOWLEDGE);
        $acknowledgeCallback->reject();

        $this->assertNull($amqpBackedMessageChannel->receive());
    }

    /**
     * @throws MessagingException
     */
    public function test_sending_and_receiving_from_fanout_exchange()
    {
        $exchangeName = Uuid::uuid4()->toString();
        $whiteQueueName = Uuid::uuid4()->toString();
        $blackQueueName = Uuid::uuid4()->toString();
        $amqpQueues = [
            AmqpQueue::createWith($blackQueueName),
            AmqpQueue::createWith($whiteQueueName),
        ];
        $amqpExchanges = [
            AmqpExchange::createFanoutExchange($exchangeName),
        ];
        $amqpBindings = [
            AmqpBinding::createFromNamesWithoutRoutingKey($exchangeName, $whiteQueueName),
            AmqpBinding::createFromNamesWithoutRoutingKey($exchangeName, $blackQueueName),
        ];
        $requestChannelName = 'requestChannel';
        $inboundRequestChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $converters = [];
        $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);


        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName, 'an-id');
        $inboundAmqpAdapterForWhite = $this->createAmqpInboundAdapter($whiteQueueName, $requestChannelName, $amqpConnectionReferenceName, 'an-other-id');

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create($exchangeName, $amqpConnectionReferenceName);
        $this->send($outboundAmqpGatewayBuilder, MessageBuilder::withPayload('some')->build());

        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel));
        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForWhite, $inboundRequestChannel));
    }

    /**
     * @param string $queueName
     * @return EnqueueMessageChannel
     * @throws MessagingException
     */
    private function createDirectAmqpBackendMessageChannel(string $queueName): PollableChannel
    {
        $amqpConnectionReferenceName = 'amqpConnectionName';
        $this->createReferenceSearchService(
            $amqpConnectionReferenceName,
            [],
            [AmqpQueue::createWith($queueName)],
            [],
            []
        );

        return $this->componentTest->build(AmqpBackedMessageChannelBuilder::create($queueName, $amqpConnectionReferenceName)
            ->withReceiveTimeout(1));
    }

    private function acceptMessage(Message $message): void
    {
        /** @var AcknowledgementCallback $acknowledgeCallback */
        $acknowledgeCallback = $message->getHeaders()->get(AmqpHeader::HEADER_ACKNOWLEDGE);
        $acknowledgeCallback->accept();
    }
}
