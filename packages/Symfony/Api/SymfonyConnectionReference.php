<?php

declare(strict_types=1);

namespace Ecotone\Api\Symfony;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Messaging\Config\ConfigurationException;
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
    public const DEFAULT_CONNECTION_POOL_SIZE = 10;
    public const DEFAULT_CONNECTION_POOL_WAIT_SECONDS = 5.0;

    private function __construct(
        private string $referenceName,
        private ?string $managerRegistryReference,
        ?string $connectionName,
        public readonly int $connectionPoolSize = self::DEFAULT_CONNECTION_POOL_SIZE,
        public readonly float $connectionPoolWaitSeconds = self::DEFAULT_CONNECTION_POOL_WAIT_SECONDS,
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

    public static function createForConnection(string $connectionName, ?string $referenceName = null, int $connectionPoolSize = self::DEFAULT_CONNECTION_POOL_SIZE, float $connectionPoolWaitSeconds = self::DEFAULT_CONNECTION_POOL_WAIT_SECONDS): self
    {
        return new self(
            referenceName: $referenceName ?? $connectionName . '.' . Uuid::v7()->toRfc4122(),
            managerRegistryReference: null,
            connectionName: $connectionName,
            connectionPoolSize: $connectionPoolSize,
            connectionPoolWaitSeconds: $connectionPoolWaitSeconds,
        );
    }

    public function withConnectionPoolSize(int $connectionPoolSize): self
    {
        if ($this->isManagerRegistryBasedConnection()) {
            throw ConfigurationException::create(
                "Connection pool size cannot be set for `{$this->connectionName}`, because it is taken from a Doctrine ManagerRegistry: its EntityManager is bound to the one connection Symfony built, so Ecotone opens no further connections for it. "
                . "Register the connection without the registry to have a connection per concurrent execution: SymfonyConnectionReference::defaultConnection('{$this->connectionName}')->withConnectionPoolSize({$connectionPoolSize}), or remove withConnectionPoolSize()."
            );
        }
        if ($connectionPoolSize < 1) {
            throw ConfigurationException::create(
                "Connection pool size for `{$this->connectionName}` is {$connectionPoolSize}, but a pool needs at least one connection. "
                . 'Pass a positive number to withConnectionPoolSize(), or leave it out for the default of ' . self::DEFAULT_CONNECTION_POOL_SIZE . '.'
            );
        }

        return new self(referenceName: $this->referenceName, managerRegistryReference: null, connectionName: $this->connectionName, connectionPoolSize: $connectionPoolSize, connectionPoolWaitSeconds: $this->connectionPoolWaitSeconds);
    }

    public function withConnectionPoolWaitSeconds(float $connectionPoolWaitSeconds): self
    {
        if ($this->isManagerRegistryBasedConnection()) {
            throw ConfigurationException::create(
                "Connection pool wait cannot be set for `{$this->connectionName}`, because it is taken from a Doctrine ManagerRegistry: its EntityManager is bound to the one connection Symfony built, so there is no pool to wait on. "
                . "Register the connection without the registry to have a connection per concurrent execution: SymfonyConnectionReference::defaultConnection('{$this->connectionName}')->withConnectionPoolWaitSeconds({$connectionPoolWaitSeconds}), or remove withConnectionPoolWaitSeconds()."
            );
        }
        if ($connectionPoolWaitSeconds < 0) {
            throw ConfigurationException::create(
                "Connection pool wait for `{$this->connectionName}` is {$connectionPoolWaitSeconds} seconds, but a wait cannot be negative. "
                . 'Pass 0 to withConnectionPoolWaitSeconds() to fail at once when every connection is in use, a number of seconds to wait for one, or leave it out for the default of ' . self::DEFAULT_CONNECTION_POOL_WAIT_SECONDS . ' seconds.'
            );
        }

        return new self(referenceName: $this->referenceName, managerRegistryReference: null, connectionName: $this->connectionName, connectionPoolSize: $this->connectionPoolSize, connectionPoolWaitSeconds: $connectionPoolWaitSeconds);
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
                $this->connectionPoolSize,
                $this->connectionPoolWaitSeconds,
            ],
            [
                self::class,
                'createForConnection',
            ]
        );
    }
}
