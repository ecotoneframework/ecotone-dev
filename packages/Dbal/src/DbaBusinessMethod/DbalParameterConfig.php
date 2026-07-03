<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbaBusinessMethod;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionContextExecutor;

/**
 * Compiled runtime representation of DbalParameter attribute, with expression replaced by executable program.
 */
/**
 * licence Apache-2.0
 */
final class DbalParameterConfig
{
    public function __construct(
        private ?string $name,
        private int|ArrayParameterType|ParameterType|null $type,
        private ?string $convertToMediaType,
        private ?AttributeExpressionContextExecutor $expressionExecutor = null,
    ) {
    }

    public static function fromAttribute(DbalParameter $dbalParameter, ?AttributeExpressionContextExecutor $expressionExecutor): self
    {
        return new self(
            $dbalParameter->getName(),
            $dbalParameter->getType(),
            $dbalParameter->getConvertToMediaType(),
            $expressionExecutor,
        );
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getType(): int|ArrayParameterType|ParameterType|null
    {
        return $this->type;
    }

    public function getConvertToMediaType(): ?string
    {
        return $this->convertToMediaType;
    }

    public function getExpressionExecutor(): ?AttributeExpressionContextExecutor
    {
        return $this->expressionExecutor;
    }
}
