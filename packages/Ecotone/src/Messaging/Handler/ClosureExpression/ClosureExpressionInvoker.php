<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Message;

/**
 * licence Enterprise
 */
final class ClosureExpressionInvoker
{
    /**
     * @param ClosureParameterResolver[] $parameterResolvers
     */
    public function __construct(
        private Closure $expression,
        private array $parameterResolvers,
    ) {
    }

    public function invoke(Message $message, array $additionalContext = []): mixed
    {
        $arguments = [];
        foreach ($this->parameterResolvers as $parameterResolver) {
            $arguments[] = $parameterResolver->resolve($message, $additionalContext);
        }

        return ($this->expression)(...$arguments);
    }
}
