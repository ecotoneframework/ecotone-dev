<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Unit;

use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class JMSConverterConfigurationCopyTest extends TestCase
{
    public function test_with_naming_strategy_changes_only_the_naming_strategy(): void
    {
        $original = $this->everyFieldPopulated();

        $copy = $original->withNamingStrategy(JMSConverterConfiguration::IDENTICAL_PROPERTY_NAMING_STRATEGY);

        $this->assertSame([JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY, false, false, true], $this->fieldsOf($original));
        $this->assertSame([JMSConverterConfiguration::IDENTICAL_PROPERTY_NAMING_STRATEGY, false, false, true], $this->fieldsOf($copy));
    }

    public function test_with_default_null_serialization_changes_only_the_null_serialization(): void
    {
        $original = $this->everyFieldPopulated();

        $copy = $original->withDefaultNullSerialization(true);

        $this->assertSame([JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY, false, false, true], $this->fieldsOf($original));
        $this->assertSame([JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY, true, false, true], $this->fieldsOf($copy));
    }

    public function test_with_default_enum_support_changes_only_the_enum_support(): void
    {
        $original = $this->everyFieldPopulated();

        $copy = $original->withDefaultEnumSupport(true);

        $this->assertSame([JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY, false, false, true], $this->fieldsOf($original));
        $this->assertSame([JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY, false, true, true], $this->fieldsOf($copy));
    }

    public function test_with_metadata_cache_freshness_check_changes_only_the_freshness_check(): void
    {
        $original = $this->everyFieldPopulated();

        $copy = $original->withMetadataCacheFreshnessCheck(false);

        $this->assertSame([JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY, false, false, true], $this->fieldsOf($original));
        $this->assertSame([JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY, false, false, false], $this->fieldsOf($copy));
    }

    private function everyFieldPopulated(): JMSConverterConfiguration
    {
        return JMSConverterConfiguration::createWithDefaults()
            ->withNamingStrategy(JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY)
            ->withDefaultNullSerialization(false)
            ->withDefaultEnumSupport(false)
            ->withMetadataCacheFreshnessCheck(true);
    }

    /**
     * @return array{string, bool, bool, ?bool}
     */
    private function fieldsOf(JMSConverterConfiguration $configuration): array
    {
        return [$configuration->namingStrategy, $configuration->defaultNullSerialization, $configuration->enableEnumSupport, $configuration->metadataCacheFreshnessCheck];
    }
}
