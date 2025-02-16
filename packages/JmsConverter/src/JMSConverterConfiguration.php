<?php

namespace Ecotone\JMSConverter;

/**
 * Class JMSConverterConfiguration
 * @package Ecotone\JMSConverter\Configuration
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class JMSConverterConfiguration
{
    public const IDENTICAL_PROPERTY_NAMING_STRATEGY = 'identicalPropertyNamingStrategy';
    public const CAMEL_CASE_PROPERTY_NAMING_STRATEGY = 'camelCasePropertyNamingStrategy';

    public function __construct(
        private string $namingStrategy = self::IDENTICAL_PROPERTY_NAMING_STRATEGY,
        /** @TODO Ecotone 2.0 - make default yes */
        private bool $defaultNullSerialization = false,
        /** @TODO Ecotone 2.0 - make default yes */
        private bool $enableEnumSupport = false,
    ) {
    }

    public static function createWithDefaults()
    {
        return new self();
    }

    public function withNamingStrategy(string $namingStrategy): static
    {
        $this->namingStrategy = $namingStrategy;

        return $this;
    }

    public function withDefaultNullSerialization(bool $isEnabled): static
    {
        $this->defaultNullSerialization = $isEnabled;

        return $this;
    }

    public function withDefaultEnumSupport(bool $enabled): static
    {
        $this->enableEnumSupport = $enabled;

        return $this;
    }

    public function getNamingStrategy(): string
    {
        return $this->namingStrategy;
    }

    public function getDefaultNullSerialization(): bool
    {
        return $this->defaultNullSerialization;
    }

    public function isEnumSupportEnabled(): bool
    {
        return $this->enableEnumSupport;
    }
}
