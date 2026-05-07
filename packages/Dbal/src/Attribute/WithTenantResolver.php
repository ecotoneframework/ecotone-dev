<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;

/**
 * licence Enterprise
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class WithTenantResolver
{
    public function __construct(public string $expression)
    {
    }

    public function getExpression(): string
    {
        return $this->expression;
    }
}
