<?php

namespace Test\Ecotone\DataProtection\Integration;

use Ecotone\DataProtection\Configuration\ChannelProtectionConfiguration;
use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\DataProtection\Conversion\XmlHelper;
use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Exception\CryptoException;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\EncryptMessagesWithChannelConfiguration\TestCommandHandler;
use Test\Ecotone\DataProtection\Fixture\EncryptMessagesWithChannelConfiguration\TestEventHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;
use Test\Ecotone\DataProtection\TestQueueChannel;
use Throwable;

/**
 * @internal
 */
class EncryptMessagesWithChannelConfigurationXMLTest extends TestCase
{
    private Key $primaryKey;
    private Key $secondaryKey;

    protected function setUp(): void
    {
        $this->primaryKey = Key::createNewRandomKey();
        $this->secondaryKey = Key::createNewRandomKey();
    }

    public function test_protect_commands_using_channel_configuration_with_default_encryption_key(): void
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
        $messagePayload = Crypto::decrypt($channelMessage->getPayload(), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        $expectedPayload = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <result>
              <class>
                <argument><![CDATA[value]]></argument>
                <enum><![CDATA[first]]></enum>
              </class>
              <enum><![CDATA[first]]></enum>
              <argument><![CDATA[value]]></argument>
            </result>

            XML;
        self::assertEquals($expectedPayload, $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_protect_commands_using_channel_configuration_with_default_encryption_key_and_no_sensitive_payload(): void
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

        $expectedPayload = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <result>
              <class>
                <argument><![CDATA[value]]></argument>
                <enum><![CDATA[first]]></enum>
              </class>
              <enum><![CDATA[first]]></enum>
              <argument><![CDATA[value]]></argument>
            </result>

            XML;
        self::assertEquals($expectedPayload, $channelMessage->getPayload());
        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_protect_commands_using_channel_configuration_with_non_default_encryption_key(): void
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
        $messagePayload = Crypto::decrypt($channelMessage->getPayload(), $this->secondaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        $expectedPayload = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <result>
              <class>
                <argument><![CDATA[value]]></argument>
                <enum><![CDATA[first]]></enum>
              </class>
              <enum><![CDATA[first]]></enum>
              <argument><![CDATA[value]]></argument>
            </result>

            XML;
        self::assertEquals($expectedPayload, $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->secondaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->secondaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_protect_commands_using_channel_configuration_with_default_encryption_key_and_routing_key(): void
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
        $messagePayload = Crypto::decrypt($channelMessage->getPayload(), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        $expectedPayload = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <result/>

            XML;
        self::assertEquals($expectedPayload, $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_protect_commands_using_channel_configuration_with_default_encryption_key_and_routing_key_with_no_sensitive_payload(): void
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

        $expectedPayload = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <result/>

            XML;
        self::assertEquals($expectedPayload, $channelMessage->getPayload());
        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_protect_annotated_commands_using_channel_configuration_with_default_encryption_key_and_routing_key_with_no_sensitive_payload(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test')
            ->withEncryptionKey('secondary')
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone(
            channelProtectionConfiguration: $channelProtectionConfiguration,
            messageChannel: $channel = TestQueueChannel::create('test'),
            receivedMessage: $messageReceiver = new MessageReceiver(),
            classesToResolve: [AnnotatedMessage::class],
        );

        $ecotone
            ->sendCommand(
                command: $messageSent = new AnnotatedMessage(
                    sensitiveObject: new TestClass('value', TestEnum::FIRST),
                    sensitiveEnum: TestEnum::FIRST,
                    sensitiveProperty: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        // assert channel message
        $channelMessage = $channel->getLastSentMessage();
        $channelMessagePayload = Crypto::decrypt($channelMessage->getPayload(), $this->secondaryKey);

        $expectedPayload = <<<XML
            <?xml version="1.0"?>
            <result><sensitiveObject><argument>value</argument><enum>first</enum></sensitiveObject><sensitiveEnum>first</sensitiveEnum><sensitiveProperty>value</sensitiveProperty></result>

            XML;
        self::assertEquals($expectedPayload, $this->decryptChannelMessagePayload($channelMessagePayload, $this->primaryKey));
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->secondaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->secondaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
        self::assertFalse($messageHeaders->containsKey('fos'), 'encryption should not add additional headers');


        // assert received message
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);
    }

    public function test_protect_events_using_channel_configuration_with_default_encryption_key(): void
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
        $messagePayload = Crypto::decrypt($channelMessage->getPayload(), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        $expectedPayload = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <result>
              <class>
                <argument><![CDATA[value]]></argument>
                <enum><![CDATA[first]]></enum>
              </class>
              <enum><![CDATA[first]]></enum>
              <argument><![CDATA[value]]></argument>
            </result>

            XML;
        self::assertEquals($expectedPayload, $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_protect_events_using_channel_configuration_with_non_default_key(): void
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
        $messagePayload = Crypto::decrypt($channelMessage->getPayload(), $this->secondaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        $expectedPayload = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <result>
              <class>
                <argument><![CDATA[value]]></argument>
                <enum><![CDATA[first]]></enum>
              </class>
              <enum><![CDATA[first]]></enum>
              <argument><![CDATA[value]]></argument>
            </result>

            XML;
        self::assertEquals($expectedPayload, $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->secondaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->secondaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_protect_events_using_channel_configuration_and_routing_key(): void
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
        $messagePayload = Crypto::decrypt($channelMessage->getPayload(), $this->primaryKey);
        $messageHeaders = $channelMessage->getHeaders();

        $expectedPayload = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <result/>

            XML;
        self::assertEquals($expectedPayload, $messagePayload);
        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->primaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->primaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
    }

    public function test_protecting_messages_with_non_pollable_channel_is_not_possible(): void
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

    public function test_protect_annotated_events_using_channel_configuration_with_default_encryption_key_and_routing_key_with_no_sensitive_payload(): void
    {
        $channelProtectionConfiguration = ChannelProtectionConfiguration::create('test')
            ->withEncryptionKey('secondary')
            ->withSensitiveHeader('foo')
            ->withSensitiveHeader('bar')
            ->withSensitiveHeader('fos')
        ;

        $ecotone = $this->bootstrapEcotone(
            channelProtectionConfiguration: $channelProtectionConfiguration,
            messageChannel: $channel = TestQueueChannel::create('test'),
            receivedMessage: $messageReceiver = new MessageReceiver(),
            classesToResolve: [AnnotatedMessage::class],
        );

        $ecotone
            ->publishEvent(
                event: $messageSent = new AnnotatedMessage(
                    sensitiveObject: new TestClass('value', TestEnum::FIRST),
                    sensitiveEnum: TestEnum::FIRST,
                    sensitiveProperty: 'value',
                ),
                metadata: $metadataSent = [
                    'foo' => 'secret-value',
                    'bar' => 'even-more-secret-value',
                    'baz' => 'non-sensitive-value',
                ]
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        // assert channel message
        $channelMessage = $channel->getLastSentMessage();
        $channelMessagePayload = Crypto::decrypt($channelMessage->getPayload(), $this->secondaryKey);

        $expectedPayload = <<<XML
            <?xml version="1.0"?>
            <result><sensitiveObject><argument>value</argument><enum>first</enum></sensitiveObject><sensitiveEnum>first</sensitiveEnum><sensitiveProperty>value</sensitiveProperty></result>

            XML;
        self::assertEquals($expectedPayload, $this->decryptChannelMessagePayload($channelMessagePayload, $this->primaryKey));
        $messageHeaders = $channelMessage->getHeaders();

        self::assertEquals($metadataSent['foo'], Crypto::decrypt($messageHeaders->get('foo'), $this->secondaryKey));
        self::assertEquals($metadataSent['bar'], Crypto::decrypt($messageHeaders->get('bar'), $this->secondaryKey));
        self::assertEquals($metadataSent['baz'], $messageHeaders->get('baz'));
        self::assertFalse($messageHeaders->containsKey('fos'), 'encryption should not add additional headers');


        // assert received message
        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        $receivedHeaders = $messageReceiver->receivedHeaders();
        self::assertEquals($metadataSent['foo'], $receivedHeaders['foo']);
        self::assertEquals($metadataSent['bar'], $receivedHeaders['bar']);
        self::assertEquals($metadataSent['baz'], $receivedHeaders['baz']);
    }

    private function bootstrapEcotone(
        ChannelProtectionConfiguration $channelProtectionConfiguration,
        MessageChannel $messageChannel,
        MessageReceiver $receivedMessage,
        array $classesToResolve = [],
    ): FlowTestSupport {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: [
                $receivedMessage,
                new TestCommandHandler(),
                new TestEventHandler(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\EncryptMessagesWithChannelConfiguration'])
                ->withDefaultSerializationMediaType(MediaType::APPLICATION_XML)
                ->withExtensionObjects([
                    $channelProtectionConfiguration,
                    DataProtectionConfiguration::create('primary', $this->primaryKey)
                        ->withKey('secondary', $this->secondaryKey),
                    SimpleMessageChannelBuilder::create('test', $messageChannel),
                    JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true),
                ])
        );
    }

    private function decryptChannelMessagePayload(string $payload, Key $encryptionKey): string
    {
        $payload = XmlHelper::xmlToArray($payload);
        foreach ($payload as $key => $value) {
            try {
                $payload[$key] = Crypto::decrypt($value, $encryptionKey);
            } catch (CryptoException) { // in some cases property is not encrypted
                $payload[$key] = $value;
            }
            try {
                $payload[$key] = json_decode($payload[$key], true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) { // in some cases property is not json encoded
            }
        }

        return XmlHelper::arrayToXml($payload);
    }
}
