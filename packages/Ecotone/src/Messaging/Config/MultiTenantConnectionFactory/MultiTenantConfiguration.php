<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\MultiTenantConnectionFactory;

use Enqueue\Dbal\DbalConnectionFactory;

final class MultiTenantConfiguration
{
    /**
     * @param array<string, string> $tenantToConnectionMapping
     * @param string $referenceName - Name of the reference on which connection factory will be registered in Dependency Container
     */
    private function __construct(
        private string  $tenantHeaderName,
        private array   $tenantToConnectionMapping,
        private string  $referenceName,
        private ?string $defaultConnectionName = null,
    )
    {
    }

    /**
     * @param array<string, string> $tenantToConnectionMapping
     */
    public static function create(string $tenantHeaderName, array $tenantToConnectionMapping, string $referenceName = DbalConnectionFactory::class): self
    {
        return new self($tenantHeaderName, $tenantToConnectionMapping, $referenceName);
    }

    /**
     * @param array<string, string> $tenantToConnectionMapping
     */
    public static function createWithDefaultConnection(string $tenantHeaderName, array $tenantToConnectionMapping, string $defaultConnectionName, string $referenceName = DbalConnectionFactory::class): self
    {
        return new self($tenantHeaderName, $tenantToConnectionMapping, $referenceName, $defaultConnectionName);
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    public function getTenantHeaderName(): string
    {
        return $this->tenantHeaderName;
    }

    public function getTenantToConnectionMapping(): array
    {
        return $this->tenantToConnectionMapping;
    }

    public function getDefaultConnectionName(): ?string
    {
        return $this->defaultConnectionName;
    }
}