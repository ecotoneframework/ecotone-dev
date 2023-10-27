<?php

namespace Ecotone\JMSConverter;

/**
 * Class JMSConverterConfiguration
 * @package Ecotone\JMSConverter\Configuration
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class JMSConverterConfiguration
{
    public const IDENTICAL_PROPERTY_NAMING_STRATEGY = 'identicalPropertyNamingStrategy';
    public const CAMEL_CASE_PROPERTY_NAMING_STRATEGY = 'camelCasePropertyNamingStrategy';

    public function __construct(
        private string $namingStrategy = self::IDENTICAL_PROPERTY_NAMING_STRATEGY,
        private bool $defaultNullSerialization = false
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

    public function getNamingStrategy(): string
    {
        return $this->namingStrategy;
    }

    public function getDefaultNullSerialization(): bool
    {
        return $this->defaultNullSerialization;
    }
}
