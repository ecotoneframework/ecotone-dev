<?php

namespace Test\Ecotone\DataProtection\Integration;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Ecotone\DataProtection\Configuration\ChannelProtectionConfiguration;
use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\ObfuscateChannel\TestCommandHandler;
use Test\Ecotone\DataProtection\Fixture\ObfuscateChannel\TestEventHandler;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;
use Test\Ecotone\DataProtection\TestQueueChannel;

/**
 * @internal
 */
class ObfuscateChannelTest extends TestCase
{
    private Key $primaryKey;
    private Key $secondaryKey;

    protected function setUp(): void
    {
        $this->primaryKey = Key::createNewRandomKey();
        $this->secondaryKey = Key::createNewRandomKey();
    }

    public function test_obfuscate_command_handler_channel_with_default_encryption_key(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test')
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone($channelProtectionConfiguration, $channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->sendCommand(
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
        );

        $ecotone
            ->sendCommand($messageSent, metadata: $metadataSent)
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

    public function test_obfuscate_command_handler_channel_with_default_encryption_key_and_no_sensitive_payload(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test')
            ->withSensitivePayload(false)
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone($channelProtectionConfiguration, $channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->sendCommand(
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
        );

        $ecotone
            ->sendCommand($messageSent, metadata: $metadataSent)
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);

        $channelMessage = $channel->getLastSentMessage();
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $channelMessage->getPayload());
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_obfuscate_command_handler_channel_with_non_default_key(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test', 'secondary')
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone($channelProtectionConfiguration, $channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->sendCommand(
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
        );

        $ecotone
            ->sendCommand($messageSent, metadata: $metadataSent)
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
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test')
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone($channelProtectionConfiguration, $channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

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

    public function test_obfuscate_command_handler_channel_called_with_routing_key_and_no_sensitive_payload(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test')
            ->withSensitivePayload(false)
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone($channelProtectionConfiguration, $channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

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
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_obfuscate_event_handler_channel_with_default_encryption_key(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test')
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone($channelProtectionConfiguration, $channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

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

    public function test_obfuscate_event_handler_channel_with_non_default_key(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test', 'secondary')
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone($channelProtectionConfiguration, $channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

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

    public function test_obfuscate_event_handler_channel_called_with_routing_key(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test')
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone($channelProtectionConfiguration, $channel = TestQueueChannel::create('test'), $messageReceiver = new MessageReceiver());

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

    public function test_obfuscate_non_pollable_channel(): void
    {
        $this->expectExceptionObject(InvalidArgumentException::create('`test` channel must be pollable channel to use Data Protection.'));

        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test');

        EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DATA_PROTECTION_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\NonPollable'])
                ->withExtensionObjects(
                    [
                        $channelProtectionConfiguration,
                        DataProtectionConfiguration::create('primary', $this->primaryKey)
                            ->withKey('secondary', $this->secondaryKey),
                        SimpleMessageChannelBuilder::create('test', new DirectChannel('test')),
                        JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true),
                    ]
                )
        )
        ;
    }

    private function bootstrapEcotone(ChannelProtectionConfiguration $channelProtectionConfiguration, MessageChannel $messageChannel, MessageReceiver $receivedMessage): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                $receivedMessage,
                new TestCommandHandler(),
                new TestEventHandler(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\ObfuscateChannel'])
                ->withExtensionObjects([
                    $channelProtectionConfiguration,
                    DataProtectionConfiguration::create('primary', $this->primaryKey)
                        ->withKey('secondary', $this->secondaryKey),
                    SimpleMessageChannelBuilder::create('test', $messageChannel),
                    JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true),
                ])
        );
    }
}
