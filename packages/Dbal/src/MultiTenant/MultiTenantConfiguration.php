<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant;

use Ecotone\Messaging\Config\ConnectionReference;
use Enqueue\Dbal\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
final class MultiTenantConfiguration
{
    /**
     * @param array<string, string|ConnectionReference> $tenantToConnectionMapping
     * @param string $referenceName - Name of the reference on which connection factory will be registered in Dependency Container
     */
    private function __construct(
        private string  $tenantHeaderName,
        private array   $tenantToConnectionMapping,
        private string  $referenceName,
        private string|ConnectionReference|null $defaultConnectionName = null,
    ) {
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
    public static function createWithDefaultConnection(string $tenantHeaderName, array $tenantToConnectionMapping, string|ConnectionReference $defaultConnectionName, string $referenceName = DbalConnectionFactory::class): self
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

    /**
     * @return array<string, string|ConnectionReference>
     */
    public function getTenantToConnectionMapping(): array
    {
        return $this->tenantToConnectionMapping;
    }

    public function getDefaultConnectionName(): string|ConnectionReference|null
    {
        return $this->defaultConnectionName;
    }
}
