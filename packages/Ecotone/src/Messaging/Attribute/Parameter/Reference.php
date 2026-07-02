<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Attribute\Parameter;

use Attribute;
use Closure;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Apache-2.0
 */
class Reference
{
    public string $referenceName;

    private string|Closure|null $expression;

    public function __construct(
        string $referenceName = '',
        string|Closure|null $expression = null,
    ) {
        $this->referenceName = $referenceName;
        $this->expression    = $expression;
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    public function getExpression(): string|Closure|null
    {
        return $this->expression;
    }
}
