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
use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnection;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Interop\Amqp\AmqpConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\FullyObfuscatedMessage;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\MessageWithSecondaryKeyEncryption;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\PartiallyObfuscatedMessage;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestCommandHandler;
use Test\Ecotone\DataProtection\Fixture\TestEventHandler;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

class ObfuscateAnnotatedMessagesTest extends TestCase
{
    private Key $primaryKey;
    private Key $secondaryKey;

    protected function setUp(): void
    {
        $this->primaryKey = Key::createNewRandomKey();
        $this->secondaryKey = Key::createNewRandomKey();
    }

    public function test_fully_obfuscated_command_handler_message(): void
    {
        $ecotone = $this->bootstrapEcotoneWithCommandHandler($channel = QueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->sendCommand(
            $messageSent = new FullyObfuscatedMessage(
                class: new TestClass('value', TestEnum::FIRST),
                enum: TestEnum::FIRST,
                argument: 'value',
            )
        );

        $messagePayload = json_decode($channel->receive()->getPayload(), true);

        self::assertEquals('{"argument":"value","enum":"first"}', Crypto::decrypt(base64_decode($messagePayload['class']), $this->primaryKey));
        self::assertEquals(TestEnum::FIRST->value, Crypto::decrypt(base64_decode($messagePayload['enum']), $this->primaryKey));
        self::assertEquals('value', Crypto::decrypt(base64_decode($messagePayload['argument']), $this->primaryKey));

        $ecotone->sendCommand($messageSent);
        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
    }

    public function test_partially_obfuscated_command_handler_message(): void
    {
        $ecotone = $this->bootstrapEcotoneWithCommandHandler($channel = QueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->sendCommand(
            $messageSent = new PartiallyObfuscatedMessage(
                class: new TestClass('value', TestEnum::FIRST),
                enum: TestEnum::FIRST,
                argument: 'value',
            )
        );

        $messagePayload = json_decode($channel->receive()->getPayload(), true);

        self::assertEquals('{"argument":"value","enum":"first"}', Crypto::decrypt(base64_decode($messagePayload['class']), $this->primaryKey));
        self::assertEquals(TestEnum::FIRST->value, Crypto::decrypt(base64_decode($messagePayload['enum']), $this->primaryKey));
        self::assertEquals('value', $messagePayload['argument']);

        $ecotone->sendCommand($messageSent);
        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
    }

    public function test_obfuscate_command_handler_message_with_non_default_key(): void
    {
        $ecotone = $this->bootstrapEcotoneWithCommandHandler($channel = QueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->sendCommand($messageSent = new MessageWithSecondaryKeyEncryption(argument: 'value'));

        $messagePayload = json_decode($channel->receive()->getPayload(), true);

        self::assertEquals('value', Crypto::decrypt(base64_decode($messagePayload['argument']), $this->secondaryKey));

        $ecotone->sendCommand($messageSent);
        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
    }

    public function test_fully_obfuscated_event_handler_message(): void
    {
        $ecotone = $this->bootstrapEcotoneWithEventHandler($channel = QueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->publishEvent(
            $messageSent = new FullyObfuscatedMessage(
                class: new TestClass('value', TestEnum::FIRST),
                enum: TestEnum::FIRST,
                argument: 'value',
            )
        );

        $messagePayload = json_decode($channel->receive()->getPayload(), true);

        self::assertEquals('{"argument":"value","enum":"first"}', Crypto::decrypt(base64_decode($messagePayload['class']), $this->primaryKey));
        self::assertEquals(TestEnum::FIRST->value, Crypto::decrypt(base64_decode($messagePayload['enum']), $this->primaryKey));
        self::assertEquals('value', Crypto::decrypt(base64_decode($messagePayload['argument']), $this->primaryKey));

        $ecotone->publishEvent($messageSent);
        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
    }

    public function test_partially_obfuscated_event_handler_message(): void
    {
        $ecotone = $this->bootstrapEcotoneWithEventHandler($channel = QueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->publishEvent(
            $messageSent = new PartiallyObfuscatedMessage(
                class: new TestClass('value', TestEnum::FIRST),
                enum: TestEnum::FIRST,
                argument: 'value',
            )
        );

        $messagePayload = json_decode($channel->receive()->getPayload(), true);

        self::assertEquals('{"argument":"value","enum":"first"}', Crypto::decrypt(base64_decode($messagePayload['class']), $this->primaryKey));
        self::assertEquals(TestEnum::FIRST->value, Crypto::decrypt(base64_decode($messagePayload['enum']), $this->primaryKey));
        self::assertEquals('value', $messagePayload['argument']);

        $ecotone->publishEvent($messageSent);
        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
    }

    public function test_obfuscate_event_handler_message_with_non_default_key(): void
    {
        $ecotone = $this->bootstrapEcotoneWithEventHandler($channel = QueueChannel::create('test'), $messageReceiver = new MessageReceiver());

        $ecotone->publishEvent($messageSent = new MessageWithSecondaryKeyEncryption(argument: 'value'));

        $messagePayload = json_decode($channel->receive()->getPayload(), true);

        self::assertEquals('value', Crypto::decrypt(base64_decode($messagePayload['argument']), $this->secondaryKey));

        $ecotone->publishEvent($messageSent);
        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals($messageSent, $messageReceiver->receivedMessage());
    }

    private function bootstrapEcotoneWithCommandHandler(MessageChannel $messageChannel, MessageReceiver $messageReceiver): FlowTestSupport
    {
        return $this->bootstrapEcotone([TestCommandHandler::class], [new TestCommandHandler()], $messageChannel, $messageReceiver);
    }

    private function bootstrapEcotoneWithEventHandler(MessageChannel $messageChannel, MessageReceiver $messageReceiver): FlowTestSupport
    {
        return $this->bootstrapEcotone([TestEventHandler::class], [new TestEventHandler()], $messageChannel, $messageReceiver);
    }

    private function bootstrapEcotone(array $classesToResolve, array $container, MessageChannel $messageChannel, MessageReceiver $receivedMessage): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: array_merge([
                $receivedMessage,
                AmqpConnectionFactory::class => AmqpMessagingTestCase::getRabbitConnectionFactory(),
                AmqpExtConnection::class => AmqpMessagingTestCase::getRabbitConnectionFactory(),
                AmqpLibConnection::class => AmqpMessagingTestCase::getRabbitConnectionFactory(),
            ], $container),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages'])
                ->withExtensionObjects([
                    DataProtectionConfiguration::create('primary', $this->primaryKey)
                        ->withKey('secondary', $this->secondaryKey),
                    SimpleMessageChannelBuilder::create('test', $messageChannel),
                    JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true),
                ])
        );
    }
}
