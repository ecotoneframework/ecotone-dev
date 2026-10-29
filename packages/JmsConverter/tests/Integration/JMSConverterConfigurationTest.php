<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\SerializerGateway;
use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class JMSConverterConfigurationTest extends TestCase
{
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
}
