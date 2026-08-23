<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\JMSConverter\Api\ExtensionObject\JMSConverterConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
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
                ->withModulePackages([ModulePackageList::JMS_CONVERTER_PACKAGE, ])
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
        );

        $ecotone->run('async');

        self::assertEquals(StringEnum::foo, $playground->typeHintedStringBackedEnum);
        ;
        self::assertEquals(StringEnum::foo->value, $playground->nonTypeHintedStringBackedEnum);
        self::assertEquals(NumericEnum::ONE, $playground->typeHintedIntBackedEnum);
        self::assertEquals(NumericEnum::ONE->value, $playground->nonTypeHintedIntBackedEnum);
        self::assertEquals(BasicEnum::ONE, $playground->typeHintedBasicEnum);
        self::assertEquals(BasicEnum::ONE->name, $playground->nonTypeHintedBasicEnum);
    }

    public function test_handling_enums_in_headers_async(): void
    {
        $playground = new Playground();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Playground::class],
            containerOrAvailableServices: [$playground],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::JMS_CONVERTER_PACKAGE, ])
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
