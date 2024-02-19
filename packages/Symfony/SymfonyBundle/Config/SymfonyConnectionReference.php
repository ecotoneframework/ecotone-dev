<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Config;

use Ecotone\Messaging\Config\ConnectionReference;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ramsey\Uuid\Uuid;

final class SymfonyConnectionReference extends ConnectionReference implements DefinedObject
{
    private function __construct(
        private string $referenceName,
        private string $managerRegistryReference,
        private ?string $connectionName
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
            $connectionName
        );
    }

    public function getManagerRegistryReference(): string
    {
        return $this->managerRegistryReference;
    }

    public function getDefinition(): Definition
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
}
