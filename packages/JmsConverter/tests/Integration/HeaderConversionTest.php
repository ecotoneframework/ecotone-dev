<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\JMSConverter\Fixture\EnumHeaderConversion\BasicEnum;
use Test\Ecotone\JMSConverter\Fixture\EnumHeaderConversion\Message;
use Test\Ecotone\JMSConverter\Fixture\EnumHeaderConversion\NumericEnum;
use Test\Ecotone\JMSConverter\Fixture\EnumHeaderConversion\Playground;
use Test\Ecotone\JMSConverter\Fixture\EnumHeaderConversion\StringEnum;

/**
 * licence Apache-2.0
 * @internal
 */
class HeaderConversionTest extends TestCase
{
    public function test_handling_enums_in_headers(): void
    {
        $playground = new Playground();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [$playground],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    JMSConverterConfiguration::createWithDefaults()
                        ->withDefaultNullSerialization(true)
                        ->withDefaultEnumSupport(true),
                ])
                ->withNamespaces(['Test\Ecotone\JMSConverter\Fixture\EnumHeaderConversion']),
        );

        self::assertNull($playground->typeHintedStringBackedEnum);
        self::assertNull($playground->nonTypeHintedStringBackedEnum);
        self::assertNull($playground->typeHintedIntBackedEnum);
        self::assertNull($playground->nonTypeHintedIntBackedEnum);
        self::assertNull($playground->typeHintedBasicEnum);
        self::assertNull($playground->nonTypeHintedBasicEnum);

        $ecotone->publishEventWithRoutingKey(
            routingKey: 'message',
            event: new Message(),
            metadata: [
                'typeHintedStringBackedEnum' => StringEnum::foo,
                'nonTypeHintedStringBackedEnum' => StringEnum::foo,
                'typeHintedIntBackedEnum' => NumericEnum::ONE,
                'nonTypeHintedIntBackedEnum' => NumericEnum::ONE,
                'typeHintedBasicEnum' => BasicEnum::ONE,
                'nonTypeHintedBasicEnum' => BasicEnum::ONE,
            ]
        );

        self::assertEquals(StringEnum::foo, $playground->typeHintedStringBackedEnum);
        ;
        self::assertEquals(StringEnum::foo, $playground->nonTypeHintedStringBackedEnum);
        self::assertEquals(NumericEnum::ONE, $playground->typeHintedIntBackedEnum);
        self::assertEquals(NumericEnum::ONE, $playground->nonTypeHintedIntBackedEnum);
        self::assertEquals(BasicEnum::ONE, $playground->typeHintedBasicEnum);
        self::assertEquals(BasicEnum::ONE, $playground->nonTypeHintedBasicEnum);
    }

    public function test_handling_enums_in_headers_async(): void
    {
        $playground = new Playground();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Playground::class],
            containerOrAvailableServices: [$playground],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async'),
                    JMSConverterConfiguration::createWithDefaults()
                        ->withDefaultNullSerialization(true)
                        ->withDefaultEnumSupport(true),
                ])
                ->withNamespaces(['Test\Ecotone\JMSConverter\Fixture\EnumHeaderConversion']),
        );

        self::assertNull($playground->typeHintedStringBackedEnum);
        self::assertNull($playground->nonTypeHintedStringBackedEnum);
        self::assertNull($playground->typeHintedIntBackedEnum);
        self::assertNull($playground->nonTypeHintedIntBackedEnum);
        self::assertNull($playground->typeHintedBasicEnum);
        self::assertNull($playground->nonTypeHintedBasicEnum);

        $ecotone->publishEventWithRoutingKey(
            routingKey: 'message',
            event: new Message(),
            metadata: [
                'typeHintedStringBackedEnum' => StringEnum::foo,
                'nonTypeHintedStringBackedEnum' => StringEnum::foo,
                'typeHintedIntBackedEnum' => NumericEnum::ONE,
                'nonTypeHintedIntBackedEnum' => NumericEnum::ONE,
                'typeHintedBasicEnum' => BasicEnum::ONE,
                'nonTypeHintedBasicEnum' => BasicEnum::ONE,
            ]
        )
        ;

        $ecotone->run('async');

        self::assertEquals(StringEnum::foo, $playground->typeHintedStringBackedEnum);
        ;
        self::assertEquals(StringEnum::foo->value, $playground->nonTypeHintedStringBackedEnum);
        self::assertEquals(NumericEnum::ONE, $playground->typeHintedIntBackedEnum);
        self::assertEquals(NumericEnum::ONE->value, $playground->nonTypeHintedIntBackedEnum);
        self::assertEquals(BasicEnum::ONE, $playground->typeHintedBasicEnum);
        self::assertEquals(BasicEnum::ONE->name, $playground->nonTypeHintedBasicEnum);
    }
}
