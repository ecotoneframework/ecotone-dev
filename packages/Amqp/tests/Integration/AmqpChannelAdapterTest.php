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
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Conversion\ObjectToSerialized\SerializingConverter;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, $messageToSend);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);
        $message = $this->receiveOnce($inboundAmqpAdapter, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService);

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
        $requestChannel = 'exceptionChannel';
        $channelWithException = DirectChannel::create();
        $channelWithException->subscribe(ExceptionalMessageHandler::create());
        $successChannel = QueueChannel::create();
        $amqpConnectionReferenceName = 'connection';
        $messageToSend = MessageBuilder::withPayload('some')->build();
        $converters = [];
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, InMemoryChannelResolver::createEmpty(), $referenceSearchService, $messageToSend);

        $this->expectException(RuntimeException::class);

        $this->createAmqpInboundAdapter($queueName, $requestChannel, $amqpConnectionReferenceName)
            ->build(
                InMemoryChannelResolver::createFromAssociativeArray([$requestChannel => $channelWithException]),
                $referenceSearchService,
                PollingMetadata::create('')
                    ->setStopOnError(true)
                    ->setExecutionAmountLimit(1)
            )
            ->run();

        $this->createAmqpInboundAdapter($queueName, $requestChannel, $amqpConnectionReferenceName)
            ->build(
                InMemoryChannelResolver::createFromAssociativeArray([$requestChannel => $successChannel]),
                $referenceSearchService,
                PollingMetadata::create('')
                    ->setStopOnError(true)
                    ->setExecutionAmountLimit(1)
            )
            ->run();

        $this->assertNotNull($successChannel->receive());
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
        $inMemoryChannelResolver = InMemoryChannelResolver::createFromAssociativeArray(
            [
                $requestChannelName => $inboundRequestChannel,
                'errorChannel' => $errorChannel,
            ]
        );
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, $messageToSend);

        $this->expectException(RuntimeException::class);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);
        $inboundAmqpGateway = $inboundAmqpAdapter
            ->build(
                $inMemoryChannelResolver,
                $referenceSearchService,
                PollingMetadata::create('')
                    ->setErrorChannelName('errorChannel')
                    ->setStopOnError(true)
                    ->setExecutionAmountLimit(1)
            );

        $inboundAmqpGateway->run();

        $this->assertNull($errorChannel->receive());
    }

    /**
     * @param string $requestChannelName
     * @param MessageChannel $inboundRequestChannel
     *
     * @return InMemoryChannelResolver
     * @throws MessagingException
     */
    private function createChannelResolver(string $requestChannelName, MessageChannel $inboundRequestChannel): InMemoryChannelResolver
    {
        $channelResolver = InMemoryChannelResolver::createFromAssociativeArray(
            [
                $requestChannelName => $inboundRequestChannel,
            ]
        );

        return $channelResolver;
    }

    /**
     * @param string $amqpConnectionReferenceName
     * @param array $amqpExchanges
     * @param array $amqpQueues
     * @param array $amqpBindings
     * @param array $converters
     *
     * @return InMemoryReferenceSearchService
     * @throws MessagingException
     */
    private function createReferenceSearchService(string $amqpConnectionReferenceName, array $amqpExchanges, array $amqpQueues, array $amqpBindings, array $converters): InMemoryReferenceSearchService
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith(
            [
                $amqpConnectionReferenceName => $this->getCachedConnectionFactory(),
                AmqpAdmin::REFERENCE_NAME => AmqpAdmin::createWith($amqpExchanges, $amqpQueues, $amqpBindings),
                ConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith($converters),
            ]
        );

        return $referenceSearchService;
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
    private function send(AmqpOutboundChannelAdapterBuilder $outboundAmqpGatewayBuilder, ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService, Message $messageToSend)
    {
        $outboundAmqpGatewayBuilder
            ->withAutoDeclareOnSend(true)
            ->build($channelResolver, $referenceSearchService)->handle($messageToSend);
    }

    /**
     * @param string $queueName
     * @param string $requestChannelName
     * @param string $amqpConnectionReferenceName
     *
     * @return AmqpInboundChannelAdapterBuilder
     * @throws Exception
     */
    private function createAmqpInboundAdapter(string $queueName, string $requestChannelName, string $amqpConnectionReferenceName): AmqpInboundChannelAdapterBuilder
    {
        return AmqpInboundChannelAdapterBuilder::createWith(
            Uuid::uuid4()->toString(),
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
    private function receiveOnce(AmqpInboundChannelAdapterBuilder $inboundAmqpGatewayBuilder, QueueChannel $inboundRequestChannel, ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): ?Message
    {
        return $this->receiveWithPollingMetadata($inboundAmqpGatewayBuilder, $inboundRequestChannel, $channelResolver, $referenceSearchService, PollingMetadata::create('someId')->setExecutionAmountLimit(1)->setExecutionTimeLimitInMilliseconds(100));
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
    private function receiveWithPollingMetadata(AmqpInboundChannelAdapterBuilder $inboundAmqpGatewayBuilder, MessageChannel $inboundRequestChannel, ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService, PollingMetadata $pollingMetadata): ?Message
    {
        $inboundAmqpGateway = $inboundAmqpGatewayBuilder
            ->build($channelResolver, $referenceSearchService, $pollingMetadata);
        $inboundAmqpGateway->run();

        return $inboundRequestChannel->receive();
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName);

        $this->expectException(InvalidArgumentException::class);

        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, $messageToSend);
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName);

        $this->expectException(InvalidArgumentException::class);

        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, $messageToSend);
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);

        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, $messageToSend);


        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);
        $message = $this->receiveOnce($inboundAmqpAdapter, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService);

        $this->assertNotNull($message, 'Message was not received from rabbit');
        $this->assertEquals(
            $message->getPayload(),
            serialize($payload)
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $inboundAmqpGatewayBuilder = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);

        $this->assertNull($this->receiveOnce($inboundAmqpGatewayBuilder, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);


        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName);
        $inboundAmqpAdapterForWhite = $this->createAmqpInboundAdapter($whiteQueueName, $requestChannelName, $amqpConnectionReferenceName);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create($exchangeName, $amqpConnectionReferenceName)
            ->withDefaultRoutingKey('white');
        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, MessageBuilder::withPayload('some')->build());

        $this->assertNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForWhite, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, [], $amqpQueues, [], $converters);


        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create('', $amqpConnectionReferenceName)
            ->withRoutingKeyFromHeader('routingKey');
        $this->send(
            $outboundAmqpGatewayBuilder,
            $inMemoryChannelResolver,
            $referenceSearchService,
            MessageBuilder::withPayload('some')
                ->setHeader('routingKey', $blackQueueName)
                ->build()
        );

        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create('', $amqpConnectionReferenceName)
            ->withExchangeFromHeader('exchangeKey');
        $this->send(
            $outboundAmqpGatewayBuilder,
            $inMemoryChannelResolver,
            $referenceSearchService,
            MessageBuilder::withPayload('some')
                ->setHeader('exchangeKey', $exchangeName)
                ->build()
        );

        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);


        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName);
        $inboundAmqpAdapterForWhite = $this->createAmqpInboundAdapter($whiteQueueName, $requestChannelName, $amqpConnectionReferenceName);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create($exchangeName, $amqpConnectionReferenceName)
            ->withDefaultRoutingKey('color.white');
        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, MessageBuilder::withPayload('some')->build());

        $this->assertNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForWhite, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $messageToSend = MessageBuilder::withPayload('some')
            ->setHeader('token', '123')
            ->setHeader('userId', 2)
            ->setHeader('userName', 'Johny')
            ->setHeader('userSurname', 'Casa')
            ->build();
        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withHeaderMapper('token,user*')
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, $messageToSend);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName)
            ->withHeaderMapper('token, userName');
        $message = $this->receiveOnce($inboundAmqpAdapter, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService);

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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, $messageToSend);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);

        $inboundQueueChannel = QueueChannel::create();
        $inboundRequestChannel->subscribe(ForwardMessageHandler::create($inboundQueueChannel));

        $inboundAmqpGateway = $inboundAmqpAdapter
            ->build($inMemoryChannelResolver, $referenceSearchService, PollingMetadata::create('')->setHandledMessageLimit(1));
        $inboundAmqpGateway->run();

        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapter, $inboundQueueChannel, $inMemoryChannelResolver, $referenceSearchService), 'Message was not requeued correctly');

        $this->assertNull($this->receiveOnce($inboundAmqpAdapter, $inboundQueueChannel, $inMemoryChannelResolver, $referenceSearchService), 'Message was not acked correctly');
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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
            ->withDefaultTimeToLive(1)
            ->withDefaultRoutingKey($queueName);
        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, $messageToSend);

        $inboundAmqpAdapter = $this->createAmqpInboundAdapter($queueName, $requestChannelName, $amqpConnectionReferenceName);
        $inboundQueueChannel = QueueChannel::create();
        $inboundRequestChannel->subscribe(ForwardMessageHandler::create($inboundQueueChannel));

        $inboundAmqpGateway = $inboundAmqpAdapter
            ->build($inMemoryChannelResolver, $referenceSearchService, PollingMetadata::create('')->setExecutionTimeLimitInMilliseconds(1000));

        usleep(1500);
        $inboundAmqpGateway->run();

        $this->assertNull($this->receiveOnce($inboundAmqpAdapter, $inboundQueueChannel, $inMemoryChannelResolver, $referenceSearchService), 'Message was did no expire');
    }

    public function test_delaying_the_message()
    {
        $queueName = Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [],
            [
                AmqpConnectionFactory::class => $this->getRabbitConnectionFactory(),
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
        $deadLettterQueueEndpointId = 'asynchronous_endpoint';
        $queueName = Uuid::uuid4()->toString();
        $deadLetterQueueName = Uuid::uuid4()->toString();
        $deadLetterQueue = AmqpQueue::createWith($deadLetterQueueName);
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [ExceptionalMessageHandler::class, AmqpConsumerExample::class],
            [
                ExceptionalMessageHandler::createWithRejectException(),
                new AmqpConsumerExample(),
                AmqpConnectionFactory::class => $this->getRabbitConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessageConsumerConfiguration::create($normalQueueEndpointId, $queueName),
                    AmqpMessageConsumerConfiguration::create($deadLettterQueueEndpointId, $deadLetterQueueName),
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

        $ecotoneLite->run($normalQueueEndpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $ecotoneLite->run($deadLettterQueueEndpointId, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
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
        $message = $amqpBackedMessageChannel->receive();

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
        $message = $amqpBackedMessageChannel->receive();
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
        $message = $amqpBackedMessageChannel->receive();

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
        $inMemoryChannelResolver = $this->createChannelResolver($requestChannelName, $inboundRequestChannel);
        $referenceSearchService = $this->createReferenceSearchService($amqpConnectionReferenceName, $amqpExchanges, $amqpQueues, $amqpBindings, $converters);


        $inboundAmqpAdapterForBlack = $this->createAmqpInboundAdapter($blackQueueName, $requestChannelName, $amqpConnectionReferenceName);
        $inboundAmqpAdapterForWhite = $this->createAmqpInboundAdapter($whiteQueueName, $requestChannelName, $amqpConnectionReferenceName);

        $outboundAmqpGatewayBuilder = AmqpOutboundChannelAdapterBuilder::create($exchangeName, $amqpConnectionReferenceName);
        $this->send($outboundAmqpGatewayBuilder, $inMemoryChannelResolver, $referenceSearchService, MessageBuilder::withPayload('some')->build());

        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForBlack, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
        $this->assertNotNull($this->receiveOnce($inboundAmqpAdapterForWhite, $inboundRequestChannel, $inMemoryChannelResolver, $referenceSearchService));
    }

    /**
     * @param string $queueName
     * @return EnqueueMessageChannel
     * @throws MessagingException
     */
    private function createDirectAmqpBackendMessageChannel(string $queueName): PollableChannel
    {
        $amqpConnectionReferenceName = 'amqpConnectionName';
        $referenceSearchService = $this->createReferenceSearchService(
            $amqpConnectionReferenceName,
            [],
            [AmqpQueue::createWith($queueName)],
            [],
            []
        );

        return AmqpBackedMessageChannelBuilder::create($queueName, $amqpConnectionReferenceName)
            ->withReceiveTimeout(1)
            ->build($referenceSearchService);
    }

    private function acceptMessage(Message $message): void
    {
        /** @var AcknowledgementCallback $acknowledgeCallback */
        $acknowledgeCallback = $message->getHeaders()->get(AmqpHeader::HEADER_ACKNOWLEDGE);
        $acknowledgeCallback->accept();
    }
}
