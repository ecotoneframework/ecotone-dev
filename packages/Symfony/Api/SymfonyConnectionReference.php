<?php

declare(strict_types=1);

namespace Ecotone\Api\Symfony;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Support\Assert;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionDbalGuard;
use Symfony\Component\Uid\Uuid;

/**
 * licence Apache-2.0
 */
final class SymfonyConnectionReference extends ConnectionReference implements DefinedObject
{
    private function __construct(
        private string $referenceName,
        private ?string $managerRegistryReference,
        ?string $connectionName,
    ) {
        parent::__construct($referenceName, $connectionName);
    }

    public static function createForManagerRegistry(
        string $connectionName,
        string $managerRegistryReference = 'doctrine',
        ?string $referenceName = null
    ): self {
        return new self(
            $referenceName ?? $managerRegistryReference . '.' . $connectionName . '.' . Uuid::v7()->toRfc4122(),
            $managerRegistryReference,
            $connectionName,
        );
    }

    public static function createForConnection(string $connectionName, ?string $referenceName = null): self
    {
        return new self(
            $referenceName ?? $connectionName . '.' . Uuid::v7()->toRfc4122(),
            null,
            $connectionName,
        );
    }

    public static function defaultManagerRegistry(
        string $connectionName,
        string $managerRegistryReference = 'doctrine',
    ): self {
        SymfonyConnectionDbalGuard::assertDbalInstalledFor("SymfonyConnectionReference::defaultManagerRegistry('{$connectionName}')");

        return self::createForManagerRegistry(
            $connectionName,
            $managerRegistryReference,
            DbalConnectionReference::DEFAULT,
        );
    }

    public static function defaultConnection(string $connectionName)
    {
        SymfonyConnectionDbalGuard::assertDbalInstalledFor("SymfonyConnectionReference::defaultConnection('{$connectionName}')");

        return new self(
            DbalConnectionReference::DEFAULT,
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
