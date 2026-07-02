<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * Marks interceptor parameter to receive compiled ClosureExpressionInvoker for given attribute class,
 * when related intercepted endpoint attribute contains closure expression.
 */
/**
 * licence Enterprise
 */
final class InvokerFor
{
    public function __construct(public string $attributeClassName)
    {
    }
}
