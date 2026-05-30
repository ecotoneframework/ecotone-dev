<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\Messaging\Config\ConnectionReference;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Enqueue\Dbal\DbalConnectionFactory;
use Tempest\Database\Config\DatabaseConfig;

/**
 * licence Apache-2.0
 */
final class TempestConnectionReference extends ConnectionReference implements DefinedObject
{
    private function __construct(
        string $referenceName,
        private readonly ?DatabaseConfig $databaseConfig = null,
    ) {
        parent::__construct($referenceName, $referenceName);
    }

    public static function create(string $referenceName, ?DatabaseConfig $databaseConfig = null): self
    {
        return new self($referenceName, $databaseConfig);
    }

    public static function defaultConnection(): self
    {
        return new self(DbalConnectionFactory::class);
    }

    public static function clearRegistry(): void
    {
    }

    public function getDatabaseConfig(): ?DatabaseConfig
    {
        return $this->databaseConfig;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            TempestConnectionReference::class,
            [
                $this->getReferenceName(),
                $this->databaseConfig !== null ? base64_encode(serialize($this->databaseConfig)) : null,
            ],
            [
                self::class,
                'createFromSerializedConfig',
            ]
        );
    }

    public static function createFromSerializedConfig(string $referenceName, ?string $serializedConfig = null): self
    {
        $config = $serializedConfig !== null ? unserialize(base64_decode($serializedConfig)) : null;

        return new self($referenceName, $config);
    }
}
