<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\Messaging\Config\ConnectionReference;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Enqueue\Dbal\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
final class TempestConnectionReference extends ConnectionReference implements DefinedObject
{
    private function __construct(
        string $referenceName,
        private readonly ?string $configTag = null,
    ) {
        parent::__construct($referenceName, $referenceName);
    }

    /**
     * Reference resolved from a tagged Tempest DatabaseConfig in the container.
     * No credentials are stored here or in Ecotone's compiled cache — the config
     * is looked up at runtime from Tempest's container by tag.
     *
     * Register the matching config in a Tempest *.config.php:
     *   return new PostgresConfig(host: '...', tag: 'tenant_a');
     */
    public static function create(string $configTag, ?string $referenceName = null): self
    {
        return new self($referenceName ?? $configTag, $configTag);
    }

    /**
     * Reference to the default (untagged) Tempest Connection singleton.
     * Shares the same PDO that Tempest's IsDatabaseModel / Database use,
     * so Ecotone's DBAL transactions wrap Tempest ORM writes on the same connection.
     */
    public static function defaultConnection(): self
    {
        return new self(DbalConnectionFactory::class, null);
    }

    public function getConfigTag(): ?string
    {
        return $this->configTag;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            TempestConnectionReference::class,
            [
                $this->getReferenceName(),
                $this->configTag,
            ],
            [
                self::class,
                'fromTagAndReferenceName',
            ]
        );
    }

    public static function fromTagAndReferenceName(string $referenceName, ?string $configTag = null): self
    {
        return new self($referenceName, $configTag);
    }
}
