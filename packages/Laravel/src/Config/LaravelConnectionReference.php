<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config;

use Ecotone\Messaging\Config\ConnectionReference;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Enqueue\Dbal\DbalConnectionFactory;

final class LaravelConnectionReference extends ConnectionReference implements DefinedObject
{
    private function __construct(
        private string $laravelConnectionName,
        string $referenceName,
    ) {
        parent::__construct($referenceName);
    }

    public static function create(string $connectionName): self
    {
        return new self($connectionName, 'ecotone.laravel.connection.' . $connectionName);
    }

    public static function createGlobalConnection(string $connectionName): self
    {
        return new self($connectionName, DbalConnectionFactory::class);
    }

    public function getLaravelConnectionName(): string
    {
        return $this->laravelConnectionName;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            LaravelConnectionReference::class,
            [
                $this->laravelConnectionName,
                $this->getReferenceName(),
            ],
        );
    }
}
