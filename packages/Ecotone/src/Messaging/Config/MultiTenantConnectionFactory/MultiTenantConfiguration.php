<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\MultiTenantConnectionFactory;

final class MultiTenantConfiguration
{
    /**
     * @param array<string, string> $connectionReferenceMapping
     */
    private function __construct(
        private string  $referenceName,
        private string  $tenantHeaderName,
        private array   $connectionReferenceMapping,
        private ?string $defaultConnectionName = null,
    )
    {
    }

    /**
     * @param array<string, string> $connectionReferenceMapping
     */
    public static function create(string $referenceName, string $tenantHeaderName, array $connectionReferenceMapping): self
    {
        return new self($referenceName, $tenantHeaderName, $connectionReferenceMapping);
    }

    /**
     * @param array<string, string> $connectionReferenceMapping
     */
    public static function createWithDefaultConnection(string $referenceName, string $tenantHeaderName, array $connectionReferenceMapping, string $defaultConnectionName): self
    {
        return new self($referenceName, $tenantHeaderName, $connectionReferenceMapping, $defaultConnectionName);
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    public function getTenantHeaderName(): string
    {
        return $this->tenantHeaderName;
    }

    public function getConnectionReferenceMapping(): array
    {
        return $this->connectionReferenceMapping;
    }

    public function getDefaultConnectionName(): ?string
    {
        return $this->defaultConnectionName;
    }
}