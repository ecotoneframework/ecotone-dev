<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;

/**
 * licence Enterprise
 */
final class RuntimeClosureExpressionParameterConverter implements ParameterConverter
{
    public function __construct(
        private ExpressionEvaluationService $expressionEvaluationService,
        private Closure $expression,
        private ?string $valueFromHeaderName = null,
        private bool $valueFromPayload = false,
        private array $staticAdditionalContext = [],
    ) {
    }

    public function getArgumentFrom(Message $message): mixed
    {
        return $this->expressionEvaluationService->evaluateWithMessage($this->expression, $message, AdditionalContextResolver::resolve($message, $this->staticAdditionalContext, $this->valueFromHeaderName, $this->valueFromPayload));
    }
}
