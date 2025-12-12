<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
/**
 * licence Apache-2.0
 */
final class DbalParameter implements DefinedObject
{
    /**
     * @param int|ArrayParameterType|ParameterType|null $type One of the \Doctrine\DBAL\ParameterType::* or \Doctrine\DBAL\ArrayParameterType constants
     */
    public function __construct(
        private ?string $name = null,
        private int|ArrayParameterType|ParameterType|null $type = null,
        private ?string $expression = null,
        private ?string $convertToMediaType = null,
        private bool $ignored = false
    ) {
    }

    public function getHeaderName(): string
    {
        return 'ecotone.dbal.business_method.' . $this->name;
    }

    public function getType(): int|ArrayParameterType|ParameterType|null
    {
        return $this->type;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getExpression(): ?string
    {
        return $this->expression;
    }

    public function getConvertToMediaType(): ?string
    {
        return $this->convertToMediaType;
    }

    public function isIgnored(): bool
    {
        return $this->ignored;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [
                $this->name,
                $this->type,
                $this->expression,
                $this->convertToMediaType,
                $this->ignored,
            ]
        );
    }
}
