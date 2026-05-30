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
    ) {
        parent::__construct($referenceName, null);
    }

    public static function create(string $referenceName): self
    {
        return new self($referenceName);
    }

    public static function defaultConnection(): self
    {
        return new self(DbalConnectionFactory::class);
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            TempestConnectionReference::class,
            [
                $this->getReferenceName(),
            ],
            [
                self::class,
                'create',
            ]
        );
    }
}
