<?php

declare(strict_types=1);

namespace Ecotone\GDPR\PersonalData;

final class PersonalDataEncryptionConfiguration
{
    private array $isEnabledFor = [];

    public function __construct(private readonly bool $useByDefault = false)
    {
    }

    public static function createWithDefaults(): self
    {
        return new self();
    }

    public function isEnabledFor(string $eventStoreReferenceName): bool
    {
        return $this->useByDefault || in_array($eventStoreReferenceName, $this->isEnabledFor, true);
    }

    public function useByDefault(bool $useByDefault = true): self
    {
        return new self($useByDefault);
    }
}
