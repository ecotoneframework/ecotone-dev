<?php

declare(strict_types=1);

namespace Ecotone\Api\Tempest;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Tempest\Config\TempestConnectionDbalGuard;

/**
 * licence Apache-2.0
 *
 * @link https://docs.ecotone.tech/modules/tempest/database-connection-dbal-module
 */
final class TempestConnectionReference extends ConnectionReference implements DefinedObject
{
    private function __construct(
        string $referenceName,
        private readonly ?string $configTag = null,
    ) {
        parent::__construct($referenceName, $referenceName);
    }

    public static function create(string $configTag, ?string $referenceName = null): self
    {
        return new self($referenceName ?? $configTag, $configTag);
    }

    public static function defaultConnection(): self
    {
        TempestConnectionDbalGuard::assertDbalInstalledFor('TempestConnectionReference::defaultConnection()');

        return new self(DbalConnectionReference::DEFAULT, null);
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
