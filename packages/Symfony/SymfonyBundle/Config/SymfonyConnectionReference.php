<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Config;

use Ecotone\Messaging\Config\ConnectionReference;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Support\Assert;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;

/**
 * licence Apache-2.0
 */
final class SymfonyConnectionReference extends ConnectionReference implements DefinedObject
{
    private function __construct(
        private string $referenceName,
        private ?string $managerRegistryReference,
        private ?string $connectionName,
    ) {
        parent::__construct($referenceName, $this->connectionName);
    }

    public static function createForManagerRegistry(
        string $connectionName,
        string $managerRegistryReference = 'doctrine',
        ?string $referenceName = null
    ): self {
        return new self(
            $referenceName ?? $managerRegistryReference . '.' . $connectionName . '.' . Uuid::uuid4()->toString(),
            $managerRegistryReference,
            $connectionName,
        );
    }

    public static function createForConnection(string $connectionName, ?string $referenceName = null): self
    {
        return new self(
            $referenceName ?? $connectionName . '.' . Uuid::uuid4()->toString(),
            null,
            $connectionName,
        );
    }

    public static function defaultManagerRegistry(
        string $connectionName,
        string $managerRegistryReference = 'doctrine',
    ): self {
        return self::createForManagerRegistry(
            $connectionName,
            $managerRegistryReference,
            DbalConnectionFactory::class,
        );
    }

    public static function defaultConnection(string $connectionName)
    {
        return new self(
            DbalConnectionFactory::class,
            null,
            $connectionName,
        );
    }

    public function getManagerRegistryReference(): string
    {
        Assert::isTrue($this->managerRegistryReference !== null, 'This connection is not manager registry based');

        return $this->managerRegistryReference;
    }

    public function isManagerRegistryBasedConnection(): bool
    {
        return $this->managerRegistryReference !== null;
    }

    public function getDefinition(): Definition
    {
        if ($this->isManagerRegistryBasedConnection()) {
            return $this->managerRegistryConnectionDefinition();
        }

        return $this->connectionDefinition();
    }

    private function managerRegistryConnectionDefinition(): Definition
    {
        return new Definition(
            SymfonyConnectionReference::class,
            [
                $this->connectionName,
                $this->managerRegistryReference,
                $this->referenceName,
            ],
            [
                self::class,
                'createForManagerRegistry',
            ]
        );
    }

    private function connectionDefinition(): Definition
    {
        return new Definition(
            SymfonyConnectionReference::class,
            [
                $this->connectionName,
                $this->referenceName,
            ],
            [
                self::class,
                'createForConnection',
            ]
        );
    }
}
