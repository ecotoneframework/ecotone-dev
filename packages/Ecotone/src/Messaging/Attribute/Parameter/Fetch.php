<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Attribute\Parameter;

use Attribute;
use Closure;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Enterprise
 */
class Fetch
{
    public string|Closure $expression;

    public function __construct(string|Closure $expression)
    {
        $this->expression = $expression;
    }

    public function getExpression(): string|Closure
    {
        return $this->expression;
    }
}
