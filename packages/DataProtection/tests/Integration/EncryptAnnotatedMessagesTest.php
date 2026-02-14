<?php

namespace Test\Ecotone\DataProtection\Integration;

use Ecotone\DataProtection\Configuration\ChannelProtectionConfiguration;
use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Exception\CryptoException;
use Ecotone\DataProtection\Encryption\Key;
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
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessageWithSensitiveProperties;
use Test\Ecotone\DataProtection\Fixture\EncryptAnnotatedMessages\TestCommandHandler;
use Test\Ecotone\DataProtection\Fixture\EncryptAnnotatedMessages\TestEventHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;
use Test\Ecotone\DataProtection\TestQueueChannel;

/**
 * @internal
 */
class EncryptAnnotatedMessagesTest extends TestCase
{
    private Key $primaryKey;
    private Key $secondaryKey;

    protected function setUp(): void
    {
        $this->primaryKey = Key::createNewRandomKey();
        $this->secondaryKey = Key::createNewRandomKey();
    }

    public function test_protect_commands_using_message_annotations(): void
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
                    sensitiveObject: new TestClass('value', TestEnum::FIRST),
                    sensitiveEnum: TestEnum::FIRST,
                    sensitiveProperty: 'value',
                ),
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals(
            '{"sensitiveObject":"{\"argument\":\"value\",\"enum\":\"first\"}","sensitiveEnum":"\"first\"","sensitiveProperty":"value"}',
            $this->decryptChannelMessagePayload($channel->getLastSentMessage()->getPayload(), $this->primaryKey)
        );
    }

    public function test_protect_commands_using_non_default_key(): void
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
        );

        $ecotone
            ->sendCommand(
                $messageSent = new AnnotatedMessageWithSecondaryEncryptionKey(
                    sensitiveObject: new TestClass('value', TestEnum::FIRST),
                    sensitiveEnum: TestEnum::FIRST,
                    sensitiveProperty: 'value',
                )
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals(
            '{"sensitiveObject":"{\"argument\":\"value\",\"enum\":\"first\"}","sensitiveEnum":"\"first\"","sensitiveProperty":"value"}',
            $this->decryptChannelMessagePayload($channel->getLastSentMessage()->getPayload(), $this->secondaryKey),
        );
    }

    public function test_protect_commands_using_property_annotation(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessageWithSensitiveProperties::class,
                TestCommandHandler::class,
            ],
            container: [
                new TestCommandHandler(),
                $messageReceiver = new MessageReceiver(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->sendCommand(
                $messageSent = new AnnotatedMessageWithSensitiveProperties(
                    sensitiveObject: new TestClass('value', TestEnum::FIRST),
                    sensitiveEnum: TestEnum::FIRST,
                    property: 'value',
                    sensitiveProperty: 'sensitive value',
                ),
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals(
            '{"sensitiveObject":"{\"argument\":\"value\",\"enum\":\"first\"}","sensitiveEnum":"\"first\"","property":"value","sensitiveProperty":"sensitive value"}',
            $this->decryptChannelMessagePayload($channel->getLastSentMessage()->getPayload(), $this->primaryKey)
        );
    }

    public function test_protect_events_using_message_annotations(): void
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
                    sensitiveObject: new TestClass('value', TestEnum::FIRST),
                    sensitiveEnum: TestEnum::FIRST,
                    sensitiveProperty: 'value',
                ),
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals(
            '{"sensitiveObject":"{\"argument\":\"value\",\"enum\":\"first\"}","sensitiveEnum":"\"first\"","sensitiveProperty":"value"}',
            $this->decryptChannelMessagePayload($channel->getLastSentMessage()->getPayload(), $this->primaryKey)
        );
    }

    public function test_protect_events_using_property_annotation(): void
    {
        $ecotone = $this->bootstrapEcotone(
            classesToResolve: [
                AnnotatedMessageWithSensitiveProperties::class,
                TestEventHandler::class,
            ],
            container: [
                new TestEventHandler(),
                $messageReceiver = new MessageReceiver(),
            ],
            messageChannel: $channel = TestQueueChannel::create('test'),
        );

        $ecotone
            ->publishEvent(
                $messageSent = new AnnotatedMessageWithSensitiveProperties(
                    sensitiveObject: new TestClass('value', TestEnum::FIRST),
                    sensitiveEnum: TestEnum::FIRST,
                    property: 'value',
                    sensitiveProperty: 'sensitive value',
                ),
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals(
            '{"sensitiveObject":"{\"argument\":\"value\",\"enum\":\"first\"}","sensitiveEnum":"\"first\"","property":"value","sensitiveProperty":"sensitive value"}',
            $this->decryptChannelMessagePayload($channel->getLastSentMessage()->getPayload(), $this->primaryKey)
        );
    }

    public function test_protect_events_using_non_default_key(): void
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
        );

        $ecotone
            ->publishEvent(
                $messageSent = new AnnotatedMessageWithSecondaryEncryptionKey(
                    sensitiveObject: new TestClass('value', TestEnum::FIRST),
                    sensitiveEnum: TestEnum::FIRST,
                    sensitiveProperty: 'value',
                ),
            )
            ->run('test', ExecutionPollingMetadata::createWithTestingSetup())
        ;

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
        self::assertEquals(
            '{"sensitiveObject":"{\"argument\":\"value\",\"enum\":\"first\"}","sensitiveEnum":"\"first\"","sensitiveProperty":"value"}',
            $this->decryptChannelMessagePayload($channel->getLastSentMessage()->getPayload(), $this->secondaryKey)
        );
    }

    private function bootstrapEcotone(array $classesToResolve, array $container, MessageChannel $messageChannel, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: $container,
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\EncryptAnnotatedMessages'])
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

    private function decryptChannelMessagePayload(string $payload, Key $primaryKey): string
    {
        $payload = json_decode($payload, true);
        foreach ($payload as $key => $value) {
            try{
                $payload[$key] = Crypto::decrypt(base64_decode($value), $primaryKey);
            } catch (CryptoException) { // in some cases property is not encrypted
                $payload[$key] = $value;
            }
        }

        return json_encode($payload);
    }
}
