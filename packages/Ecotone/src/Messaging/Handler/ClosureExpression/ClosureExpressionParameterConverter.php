<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Enterprise
 */
final class ClosureExpressionParameterConverter implements ParameterConverter
{
    public function __construct(
        private ClosureExpressionEvaluator $closureExpressionEvaluator,
        private object $attributeWithExpression,
    ) {
    }

    public function getArgumentFrom(Message $message): mixed
    {
        return $this->closureExpressionEvaluator->evaluate($this->expression(), $message);
    }

    private function expression(): Closure
    {
        $expression = $this->attributeWithExpression->getExpression();
        if (! $expression instanceof Closure) {
            throw InvalidArgumentException::create(sprintf('Expected closure expression inside %s attribute, got %s', get_class($this->attributeWithExpression), gettype($expression)));
        }

        return $expression;
    }
}
