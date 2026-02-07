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
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessageWithSecondaryEncryptionKey;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessageWithSensitiveHeaders;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\ObfuscateMessages\TestCommandHandler;
use Test\Ecotone\DataProtection\Fixture\ObfuscateMessages\TestEventHandler;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;
use Test\Ecotone\DataProtection\TestQueueChannel;

/**
 * @internal
 */
class ObfuscateMessagesTest extends TestCase
{
    private Key $primaryKey;
    private Key $secondaryKey;

    protected function setUp(): void
    {
        $this->primaryKey = Key::createNewRandomKey();
        $this->secondaryKey = Key::createNewRandomKey();
    }

    public function test_command_handler_with_obfuscate_annotated_message(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessage::class,
                TestCommandHandler::class,
            ],
            container: [
                new TestCommandHandler(),
                $messageReceiver = new MessageReceiver(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test')
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

    public function test_obfuscate_command_handler_message_with_non_default_key(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessageWithSecondaryEncryptionKey::class,
                TestCommandHandler::class,
            ],
            container: [
                new TestCommandHandler(),
                $messageReceiver = new MessageReceiver(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
            extensionObjects: [
                ChannelProtectionConfiguration::create('test', encryptionKey: 'primary'),
            ]
        );

        $ecotone
            ->sendCommand(
                $messageSent = new AnnotatedMessageWithSecondaryEncryptionKey(
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
        self::assertEquals($metadataSent['foo'], $messageHeaders->get('foo'));
        self::assertEquals($metadataSent['bar'], $messageHeaders->get('bar'));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_obfuscate_command_handler_message_with_sensitive_headers(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessageWithSensitiveHeaders::class,
                TestCommandHandler::class,
            ],
            container: [
                new TestCommandHandler(),
                $messageReceiver = new MessageReceiver(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test')
        );

        $ecotone
            ->sendCommand(
                $messageSent = new AnnotatedMessageWithSensitiveHeaders(
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
        self::assertArrayNotHasKey('fos', $receivedHeaders);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
        self::assertFalse($messageHeaders->containsKey('fos'));
    }

    public function test_obfuscate_event_handler_with_annotated_message(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessage::class,
                TestEventHandler::class,
            ],
            container: [
                new TestEventHandler(),
                $messageReceiver = new MessageReceiver(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test')
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

    public function test_obfuscate_event_handler_message_with_non_default_key(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessageWithSecondaryEncryptionKey::class,
                TestEventHandler::class,
            ],
            container: [
                new TestEventHandler(),
                $messageReceiver = new MessageReceiver(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
            extensionObjects: [
                ChannelProtectionConfiguration::create('test', encryptionKey: 'primary'),
            ]
        );

        $ecotone
            ->publishEvent(
                $messageSent = new AnnotatedMessageWithSecondaryEncryptionKey(
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
        self::assertEquals($metadataSent['foo'], $messageHeaders->get('foo'));
        self::assertEquals($metadataSent['bar'], $messageHeaders->get('bar'));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_obfuscate_event_handler_message_with_sensitive_headers(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessageWithSensitiveHeaders::class,
                TestEventHandler::class,
            ],
            container: [
                new TestEventHandler(),
                $messageReceiver = new MessageReceiver(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test')
        );

        $ecotone
            ->publishEvent(
                $messageSent = new AnnotatedMessageWithSensitiveHeaders(
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
        self::assertArrayNotHasKey('fos', $receivedHeaders);

        $channelMessage = $channel->getLastSentMessage();
        $messagePayload = Crypto::decrypt(base64_decode($channelMessage->getPayload()), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals('{"class":{"argument":"value","enum":"first"},"enum":"first","argument":"value"}', $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt(base64_decode($messageHeaders->get('foo')), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt(base64_decode($messageHeaders->get('bar')), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
        self::assertFalse($messageHeaders->containsKey('fos'));
    }

    private function bootstrapEcotone(array $classesToResolve, array $container, MessageChannel $messageChannel, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: $container,
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages'])
                ->withExtensionObjects(
                    array_merge(
                        [
                            DataProtectionConfiguration::create('primary', $this->primaryKey)
                                ->withKey('secondary', $this->secondaryKey),
                            SimpleMessageChannelBuilder::create('test', $messageChannel),
                            JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true),
                        ],
                        $extensionObjects,
                    )
                )
        );
    }
}
