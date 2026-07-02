<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;
use Closure;

/**
 * licence Enterprise
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class WithTenantResolver
{
    public function __construct(public string|Closure $expression)
    {
    }

    public function getExpression(): string|Closure
    {
        return $this->expression;
    }
}
