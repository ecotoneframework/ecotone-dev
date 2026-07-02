<?php

namespace Ecotone\Messaging\Handler;

use Closure;
use Ecotone\Messaging\Handler\ClosureExpression\ClosureExpressionEvaluator;
use Ecotone\Messaging\Message;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class StubExpressionEvaluationAdapter implements ExpressionEvaluationService
{
    public function __construct(private ReferenceSearchService $referenceSearchService)
    {
    }

    public function evaluate(string $expression, array $evaluationContext)
    {
        throw new InvalidArgumentException('Missing Symfony Expression Language, add `symfony/expression-language` in order to use expressions');
    }

    public function evaluateWithMessage(string|Closure $expression, Message $message, array $additionalContext = []): mixed
    {
        if ($expression instanceof Closure) {
            return $this->closureExpressionEvaluator()->evaluate($expression, $message, $additionalContext);
        }

        return $this->evaluate($expression, $additionalContext);
    }

    public function evaluateWithContext(string|Closure $expression, array $context): mixed
    {
        if ($expression instanceof Closure) {
            return $this->closureExpressionEvaluator()->evaluateWithContext($expression, $context);
        }

        return $this->evaluate($expression, $context);
    }

    private function closureExpressionEvaluator(): ClosureExpressionEvaluator
    {
        return $this->referenceSearchService->get(ClosureExpressionEvaluator::REFERENCE_NAME);
    }
}
