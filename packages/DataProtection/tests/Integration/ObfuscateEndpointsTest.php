<?php

namespace Test\Ecotone\DataProtection\Integration;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Ecotone\DataProtection\Configuration\ChannelProtectionConfiguration;
use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\CommandHandlerCalledWithRoutingKey;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\CommandHandlerWithAnnotatedEndpoint;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\CommandHandlerWithAnnotatedEndpointWithAlreadyAnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\CommandHandlerWithAnnotatedEndpointWithSecondaryEncryptionKey;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\CommandHandlerWithAnnotatedMethodWithoutPayload;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\CommandHandlerWithAnnotatedPayloadAndHeader;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\EventHandlerCalledWithRoutingKey;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\EventHandlerWithAnnotatedEndpoint;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\EventHandlerWithAnnotatedEndpointWithAlreadyAnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\EventHandlerWithAnnotatedEndpointWithSecondaryEncryptionKey;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\EventHandlerWithAnnotatedMethodWithoutPayload;
use Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints\EventHandlerWithAnnotatedPayloadAndHeader;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;
use Test\Ecotone\DataProtection\TestQueueChannel;

/**
 * @internal
 */
class ObfuscateEndpointsTest extends TestCase
{
    private Key $primaryKey;
    private Key $secondaryKey;

    protected function setUp(): void
    {
        $this->primaryKey = Key::createNewRandomKey();
        $this->secondaryKey = Key::createNewRandomKey();
    }

    public function test_command_handler_with_annotated_endpoint(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                CommandHandlerWithAnnotatedEndpoint::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new CommandHandlerWithAnnotatedEndpoint(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->sendCommand(
                $messageSent = new SomeMessage(
                    class: new TestClass('value', TestEnum::FIRST),
                    enum: TestEnum::FIRST,
                    argument: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_command_handler_with_annotated_endpoint_with_already_annotated_message(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessage::class,
                CommandHandlerWithAnnotatedEndpointWithAlreadyAnnotatedMessage::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new CommandHandlerWithAnnotatedEndpointWithAlreadyAnnotatedMessage(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->sendCommand(
                $messageSent = new AnnotatedMessage(
                    class: new TestClass('value', TestEnum::FIRST),
                    enum: TestEnum::FIRST,
                    argument: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], $messageHeaders->get('foo'));
        self::assertEquals($metadataSent['bar'], $messageHeaders->get('bar'));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_command_handler_with_annotated_endpoint_and_secondary_encryption_key(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                CommandHandlerWithAnnotatedEndpointWithSecondaryEncryptionKey::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new CommandHandlerWithAnnotatedEndpointWithSecondaryEncryptionKey(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->sendCommand(
                $messageSent = new SomeMessage(
                    class: new TestClass('value', TestEnum::FIRST),
                    enum: TestEnum::FIRST,
                    argument: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->secondaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->secondaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->secondaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_command_handler_called_with_routing_key(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                CommandHandlerCalledWithRoutingKey::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new CommandHandlerCalledWithRoutingKey(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->sendCommandWithRoutingKey(
                routingKey: 'command',
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('[]', $channelMessage->getPayload());
        self::assertEquals($metadataSent['foo'], $messageHeaders->get('foo'));
        self::assertEquals($metadataSent['bar'], $messageHeaders->get('bar'));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_command_handler_called_with_routing_key_will_use_channel_obfuscator(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                CommandHandlerCalledWithRoutingKey::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new CommandHandlerCalledWithRoutingKey(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
            extensionObjects: [
                ChannelProtectionConfiguration::create('test')->withSensitiveHeader('foo')->withSensitiveHeader('bar'),
            ]
        );

        $ecotone
            ->sendCommandWithRoutingKey(
                routingKey: 'command',
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('[]', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_command_handler_with_annotated_method_without_payload_will_use_channel_obfuscator(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                CommandHandlerWithAnnotatedMethodWithoutPayload::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new CommandHandlerWithAnnotatedMethodWithoutPayload(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
            extensionObjects: [
                ChannelProtectionConfiguration::create('test')->withSensitiveHeader('foo')->withSensitiveHeader('bar'),
            ]
        );

        $ecotone
            ->sendCommandWithRoutingKey(
                routingKey: 'command',
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('[]', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_command_handler_with_annotated_method_with_annotated_payload_and_header(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                CommandHandlerWithAnnotatedPayloadAndHeader::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new CommandHandlerWithAnnotatedPayloadAndHeader(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->sendCommand(
                $messageSent = new SomeMessage(
                    class: new TestClass('value', TestEnum::FIRST),
                    enum: TestEnum::FIRST,
                    argument: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
    }

    public function test_command_handler_with_annotated_method_without_payload(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                CommandHandlerWithAnnotatedMethodWithoutPayload::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new CommandHandlerWithAnnotatedMethodWithoutPayload(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->sendCommandWithRoutingKey(
                routingKey: 'command',
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('[]', $channelMessage->getPayload());
        self::assertEquals($metadataSent['foo'], $messageHeaders->get('foo'));
        self::assertEquals($metadataSent['bar'], $messageHeaders->get('bar'));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_event_handler_with_annotated_endpoint(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                EventHandlerWithAnnotatedEndpoint::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new EventHandlerWithAnnotatedEndpoint(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->publishEvent(
                $messageSent = new SomeMessage(
                    class: new TestClass('value', TestEnum::FIRST),
                    enum: TestEnum::FIRST,
                    argument: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_event_handler_with_annotated_endpoint_with_already_annotated_message(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessage::class,
                EventHandlerWithAnnotatedEndpointWithAlreadyAnnotatedMessage::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new EventHandlerWithAnnotatedEndpointWithAlreadyAnnotatedMessage(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->publishEvent(
                $messageSent = new AnnotatedMessage(
                    class: new TestClass('value', TestEnum::FIRST),
                    enum: TestEnum::FIRST,
                    argument: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], $messageHeaders->get('foo'));
        self::assertEquals($metadataSent['bar'], $messageHeaders->get('bar'));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_event_handler_with_annotated_endpoint_and_secondary_encryption_key(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                EventHandlerWithAnnotatedEndpointWithSecondaryEncryptionKey::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new EventHandlerWithAnnotatedEndpointWithSecondaryEncryptionKey(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->publishEvent(
                $messageSent = new SomeMessage(
                    class: new TestClass('value', TestEnum::FIRST),
                    enum: TestEnum::FIRST,
                    argument: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->secondaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->secondaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->secondaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_event_handler_called_with_routing_key(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                EventHandlerCalledWithRoutingKey::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new EventHandlerCalledWithRoutingKey(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->publishEventWithRoutingKey(
                routingKey: 'event',
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('[]', $channelMessage->getPayload());
        self::assertEquals($metadataSent['foo'], $messageHeaders->get('foo'));
        self::assertEquals($metadataSent['bar'], $messageHeaders->get('bar'));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_event_handler_called_with_routing_key_will_use_channel_obfuscator(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                EventHandlerCalledWithRoutingKey::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new EventHandlerCalledWithRoutingKey(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
            extensionObjects: [
                ChannelProtectionConfiguration::create('test')->withSensitiveHeader('foo')->withSensitiveHeader('bar'),
            ]
        );

        $ecotone
            ->publishEventWithRoutingKey(
                routingKey: 'event',
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('[]', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_event_handler_with_annotated_method_without_payload_will_use_channel_obfuscator(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                EventHandlerWithAnnotatedMethodWithoutPayload::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new EventHandlerWithAnnotatedMethodWithoutPayload(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
            extensionObjects: [
                ChannelProtectionConfiguration::create('test')->withSensitiveHeader('foo')->withSensitiveHeader('bar'),
            ]
        );

        $ecotone
            ->publishEventWithRoutingKey(
                routingKey: 'event',
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('[]', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_event_handler_with_annotated_method_with_annotated__payload_and_header(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                EventHandlerWithAnnotatedPayloadAndHeader::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new EventHandlerWithAnnotatedPayloadAndHeader(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->publishEvent(
                $messageSent = new SomeMessage(
                    class: new TestClass('value', TestEnum::FIRST),
                    enum: TestEnum::FIRST,
                    argument: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->secondaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->secondaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->secondaryKey));
    }

    public function test_event_handler_with_annotated_method_without_payload(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                EventHandlerWithAnnotatedMethodWithoutPayload::class,
            ],
            container: [
                $messageReceiver = new MessageReceiver(),
                new EventHandlerWithAnnotatedMethodWithoutPayload(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->publishEventWithRoutingKey(
                routingKey: 'event',
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('[]', $channelMessage->getPayload());
        self::assertEquals($metadataSent['foo'], $messageHeaders->get('foo'));
        self::assertEquals($metadataSent['bar'], $messageHeaders->get('bar'));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    private function bootstrapEcotone(array $classesToResolve, array $container, MessageChannel $messageChannel, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: $container,
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects(
                    array_merge([
                        DataProtectionConfiguration::create('primary', $this->primaryKey)
                            ->withKey('secondary', $this->secondaryKey),
                        SimpleMessageChannelBuilder::create('test', $messageChannel),
                        JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true),
                    ], $extensionObjects)
                )
        );
    }
}
