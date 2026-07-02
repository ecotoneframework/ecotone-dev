<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Enterprise
 */
final class ClosureExpressionParameterConverter implements ParameterConverter
{
    public function __construct(
        private ExpressionEvaluationService $expressionEvaluationService,
        private object $attributeWithExpression,
        private ?string $valueFromHeaderName = null,
        private bool $valueFromPayload = false,
        private array $staticAdditionalContext = [],
    ) {
    }

    public function getArgumentFrom(Message $message): mixed
    {
        return $this->expressionEvaluationService->evaluateWithMessage($this->expression(), $message, $this->additionalContextFor($message));
    }

    private function additionalContextFor(Message $message): array
    {
        $additionalContext = $this->staticAdditionalContext;
        if ($this->valueFromHeaderName !== null) {
            $additionalContext['value'] = $message->getHeaders()->containsKey($this->valueFromHeaderName) ? $message->getHeaders()->get($this->valueFromHeaderName) : null;
        } elseif ($this->valueFromPayload) {
            $additionalContext['value'] = $message->getPayload();
        }

        return $additionalContext;
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
