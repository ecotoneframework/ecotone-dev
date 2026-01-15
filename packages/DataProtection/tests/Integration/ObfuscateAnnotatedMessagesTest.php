<?php

namespace Test\Ecotone\DataProtection\Integration;

use Defuse\Crypto\Key;
use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\FullyObfuscatedMessage;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\PartiallyObfuscatedMessage;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\TestCommandHandler;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

class ObfuscateAnnotatedMessagesTest extends TestCase
{
    private Key $key;

    protected function setUp(): void
    {
        $this->key = Key::createNewRandomKey();
    }

    public function test_fully_obfuscated_message(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(
            new FullyObfuscatedMessage(
                class: new TestClass('value', TestEnum::FIRST),
                enum: TestEnum::FIRST,
                argument: 'value',
            )
        );

        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_message_with_obfuscated_enum(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(
            new PartiallyObfuscatedMessage(
                class: new TestClass('value', TestEnum::FIRST),
                enum: TestEnum::FIRST,
                argument: 'value',
            )
        );

        $ecotone->run('test', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                new TestCommandHandler(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages'])
                ->withExtensionObjects([
                    DataProtectionConfiguration::create('default', $this->key),
                    SimpleMessageChannelBuilder::createQueueChannel('test'),
                ])
        );
    }
}
