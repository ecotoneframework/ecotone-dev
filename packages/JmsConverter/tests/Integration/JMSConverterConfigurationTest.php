<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\SerializerGateway;
use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class JMSConverterConfigurationTest extends TestCase
{
    private const TWO_CONFIGURATIONS_MESSAGE = 'Ecotone was given more than one JMSConverterConfiguration: one with naming strategy identicalPropertyNamingStrategy, null serialization on, enum support on and the metadata cache freshness check following the environment, and one with naming strategy camelCasePropertyNamingStrategy, null serialization off, enum support off and the metadata cache freshness check on. Ecotone reads exactly one, so it cannot tell which of them to apply. Keep one #[ServiceContext] method returning JMSConverterConfiguration::createWithDefaults() and move every with*() call onto it, or pass a single JMSConverterConfiguration in extensionObjects.';

    public function test_a_with_call_whose_result_is_discarded_leaves_the_configuration_unchanged(): void
    {
        $configuration = JMSConverterConfiguration::createWithDefaults();
        $configuration->withDefaultNullSerialization(false);

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [$configuration],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
        );

        $this->assertSame(
            '{"seat":null}',
            $ecotone->getGateway(SerializerGateway::class)->convertFromPHP(new class () {
                public ?string $seat = null;
            }, MediaType::APPLICATION_JSON),
        );
    }

    public function test_the_configuration_returned_by_a_with_call_is_applied(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false)],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
        );

        $this->assertSame(
            '{}',
            $ecotone->getGateway(SerializerGateway::class)->convertFromPHP(new class () {
                public ?string $seat = null;
            }, MediaType::APPLICATION_JSON),
        );
    }

    public function test_two_configurations_passed_as_extension_objects_are_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::TWO_CONFIGURATIONS_MESSAGE);

        EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [
                JMSConverterConfiguration::createWithDefaults(),
                JMSConverterConfiguration::createWithDefaults()->withNamingStrategy(JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY)->withDefaultNullSerialization(false)->withDefaultEnumSupport(false)->withMetadataCacheFreshnessCheck(true),
            ],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
        );
    }

    public function test_two_configurations_returned_from_service_context_methods_are_refused_at_bootstrap(): void
    {
        $configuration = new class () {
            #[ServiceContext]
            public function serialization(): JMSConverterConfiguration
            {
                return JMSConverterConfiguration::createWithDefaults();
            }

            #[ServiceContext]
            public function camelCaseSerialization(): JMSConverterConfiguration
            {
                return JMSConverterConfiguration::createWithDefaults()->withNamingStrategy(JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY)->withDefaultNullSerialization(false)->withDefaultEnumSupport(false)->withMetadataCacheFreshnessCheck(true);
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::TWO_CONFIGURATIONS_MESSAGE);

        EcotoneLite::bootstrapFlowTesting(
            [$configuration::class],
            [$configuration],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
        );
    }

}
