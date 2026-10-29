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
        private readonly string $namingStrategy = self::IDENTICAL_PROPERTY_NAMING_STRATEGY,
        private readonly bool $defaultNullSerialization = true,
        private readonly bool $enableEnumSupport = true,
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
