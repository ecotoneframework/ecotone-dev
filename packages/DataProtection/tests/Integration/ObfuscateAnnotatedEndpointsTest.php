<?php

namespace Test\Ecotone\DataProtection\Integration;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageChannel;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedEndpoints\MessageWithSecondaryKeyEncryption;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedEndpoints\ObfuscatedMessage;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedEndpoints\TestCommandHandler;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedEndpoints\TestEventHandler;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;
use Test\Ecotone\Messaging\Unit\Channel\TestQueueChannel;

class ObfuscateAnnotatedEndpointsTest extends TestCase
{
    private Key $primaryKey;
    private Key $secondaryKey;

    protected function setUp(): void
    {
        $this->primaryKey = Key::createNewRandomKey();
        $this->secondaryKey = Key::createNewRandomKey();
    }

    public function test_obfuscate_command_handler_message(): void
    {
        $ecotone = $this->bootstrapEcotone($channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone
            ->sendCommand(
                $messageSent = new ObfuscatedMessage(
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

    public function test_obfuscate_command_handler_message_with_non_default_key(): void
    {
        $ecotone = $this->bootstrapEcotone($channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone
            ->sendCommand(
                $messageSent = new MessageWithSecondaryKeyEncryption(
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

    public function test_obfuscate_command_handler_channel_called_with_routing_key(): void
    {
        $ecotone = $this->bootstrapEcotone($channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

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

    public function test_obfuscate_event_handler_message(): void
    {
        $ecotone = $this->bootstrapEcotone($channel = QueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->publishEvent(
            $messageSent = new ObfuscatedMessage(
                class: new TestClass('value', TestEnum::FIRST),
                enum: TestEnum::FIRST,
                argument: 'value',
            ),
            metadata: $metadataSent = [
                'foo' => 'secret-value',
                'bar' => 'even-more-secret-value',
                'baz' => 'non-sensitive-value',
            ]
        );

        $channelMessage = $channel->receive();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals('secret-value', Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals('even-more-secret-value', Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals('non-sensitive-value', $messageHeaders->get('baz'));

        $ecotone->publishEvent($messageSent, metadata: $metadataSent);
        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals('secret-value', $receivedHeaders['foo']);
        self::assertEquals('even-more-secret-value', $receivedHeaders['bar']);
        self::assertEquals('non-sensitive-value', $receivedHeaders['baz']);
    }

    public function test_obfuscate_event_handler_message_with_non_default_key(): void
    {
        $ecotone = $this->bootstrapEcotone($channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone
            ->publishEvent(
                $messageSent = new MessageWithSecondaryKeyEncryption(
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

    public function test_obfuscate_event_handler_channel_called_with_routing_key(): void
    {
        $ecotone = $this->bootstrapEcotone($channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

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

    private function bootstrapEcotone(MessageChannel $messageChannel, MessageReceiver $receivedMessage): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                $receivedMessage,
                new TestCommandHandler(),
                new TestEventHandler(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedEndpoints'])
                ->withExtensionObjects([
                    DataProtectionConfiguration::create('primary', $this->primaryKey)
                        ->withKey('secondary', $this->secondaryKey),
                    SimpleMessageChannelBuilder::create('test', $messageChannel),
                    JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true),
                ])
        );
    }
}
