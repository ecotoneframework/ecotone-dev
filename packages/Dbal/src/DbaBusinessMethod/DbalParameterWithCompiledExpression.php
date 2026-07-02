<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbaBusinessMethod;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionContextExecutor;

/**
 * licence Enterprise
 */
final class DbalParameterWithCompiledExpression
{
    public function __construct(
        private ?string $name,
        private int|ArrayParameterType|ParameterType|null $type,
        private ?string $convertToMediaType,
        private AttributeExpressionContextExecutor $executor,
    ) {
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

    public function getExecutor(): AttributeExpressionContextExecutor
    {
        return $this->executor;
    }
}
