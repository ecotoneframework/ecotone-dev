<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\ExtensionObject;

use Ecotone\Api\ExtensionObject\ConnectionReference;

/**
 * licence Apache-2.0
 */
final class MultiTenantConfiguration
{
    /**
     * @param array<string, string|ConnectionReference> $tenantToConnectionMapping
     */
    private function __construct(
        public readonly string $tenantHeaderName,
        public readonly array $tenantToConnectionMapping,
        private string  $referenceName,
        public readonly string|ConnectionReference|null $defaultConnectionName = null,
    ) {
    }

    /**
     * @param array<string, string|ConnectionReference> $tenantToConnectionMapping
     */
    public static function create(string $tenantHeaderName, array $tenantToConnectionMapping, string $referenceName = DbalConnectionReference::DEFAULT): self
    {
        return new self($tenantHeaderName, $tenantToConnectionMapping, $referenceName);
    }

    /**
     * @param array<string, string|ConnectionReference> $tenantToConnectionMapping
     */
    public static function createWithDefaultConnection(string $tenantHeaderName, array $tenantToConnectionMapping, string|ConnectionReference $defaultConnectionName, string $referenceName = DbalConnectionReference::DEFAULT): self
    {
        return new self($tenantHeaderName, $tenantToConnectionMapping, $referenceName, $defaultConnectionName);
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }
}
