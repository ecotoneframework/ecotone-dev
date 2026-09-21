<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\ExtensionObject;

use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class DatabaseSetupManagerRegistry
{
    /**
     * @param array<string, DatabaseSetupManager> $managersByConnectionReferenceName
     */
    public function __construct(
        private array $managersByConnectionReferenceName,
    ) {
    }

    /**
     * @return string[]
     */
    public function getConnectionReferenceNames(): array
    {
        return array_keys($this->managersByConnectionReferenceName);
    }

    public function getManagerFor(string $connectionReferenceName): DatabaseSetupManager
    {
        if (! isset($this->managersByConnectionReferenceName[$connectionReferenceName])) {
            throw new InvalidArgumentException(sprintf(
                "No database setup manager registered for connection '%s'. Known connections: %s",
                $connectionReferenceName,
                implode(', ', $this->getConnectionReferenceNames())
            ));
        }

        return $this->managersByConnectionReferenceName[$connectionReferenceName];
    }

    public function hasConnection(string $connectionReferenceName): bool
    {
        return isset($this->managersByConnectionReferenceName[$connectionReferenceName]);
    }

    /**
     * @return array<string, DatabaseSetupManager>
     */
    public function getAllManagers(): array
    {
        return $this->managersByConnectionReferenceName;
    }
}
