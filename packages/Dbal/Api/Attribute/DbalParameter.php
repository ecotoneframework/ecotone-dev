<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Attribute;

use Attribute;
use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Ecotone\Messaging\Attribute\WithExpression;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
/**
 * licence Apache-2.0
 */
final class DbalParameter implements DefinedObject, WithExpression
{
    public function __construct(
        private ?string $name = null,
        private int|ArrayParameterType|ParameterType|null $type = null,
        private string|Closure|null $expression = null,
        public readonly ?string $convertToMediaType = null,
        public readonly bool $ignored = false
    ) {
    }

    public function getHeaderName(): string
    {
        return 'ecotone_dbal_business_method_' . $this->name;
    }

    public function getType(): int|ArrayParameterType|ParameterType|null
    {
        return $this->type;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getExpression(): string|Closure|null
    {
        return $this->expression;
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
