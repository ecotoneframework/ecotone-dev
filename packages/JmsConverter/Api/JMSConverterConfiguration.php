<?php

namespace Ecotone\Api\JMSConverter;

/**
 * licence Apache-2.0
 */
final class JMSConverterConfiguration
{
    public const IDENTICAL_PROPERTY_NAMING_STRATEGY = 'identicalPropertyNamingStrategy';
    public const CAMEL_CASE_PROPERTY_NAMING_STRATEGY = 'camelCasePropertyNamingStrategy';

    public function __construct(
        public readonly string $namingStrategy = self::IDENTICAL_PROPERTY_NAMING_STRATEGY,
        public readonly bool $defaultNullSerialization = true,
        public readonly bool $enableEnumSupport = true,
        public readonly ?bool $metadataCacheFreshnessCheck = null,
    ) {
    }

    public static function createWithDefaults(): self
    {
        return new self();
    }

    public function withNamingStrategy(string $namingStrategy): self
    {
        return new self(...[...get_object_vars($this), 'namingStrategy' => $namingStrategy]);
    }

    public function withDefaultNullSerialization(bool $isEnabled): self
    {
        return new self(...[...get_object_vars($this), 'defaultNullSerialization' => $isEnabled]);
    }

    public function withDefaultEnumSupport(bool $enabled): self
    {
        return new self(...[...get_object_vars($this), 'enableEnumSupport' => $enabled]);
    }

    public function withMetadataCacheFreshnessCheck(bool $enabled): self
    {
        return new self(...[...get_object_vars($this), 'metadataCacheFreshnessCheck' => $enabled]);
    }
}
