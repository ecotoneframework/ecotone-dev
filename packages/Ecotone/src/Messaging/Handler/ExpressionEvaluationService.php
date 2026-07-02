<?php

namespace Ecotone\Messaging\Handler;

use Closure;
use Ecotone\Messaging\Message;

/**
 * Interface ExpressionEvaluationService
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface ExpressionEvaluationService
{
    public const REFERENCE = 'expressionEvaluationService';

    /**
     * @param string $expression
     * @param array $evaluationContext
     *
     * @return mixed
     */
    public function evaluate(string $expression, array $evaluationContext);

    /**
     * Evaluates Symfony expression with `payload` and `headers` context variables, or executes given Closure with message handler alike parameter resolution.
     * Additional context variables are available in Symfony expression and are bound to Closure parameters by name.
     */
    public function evaluateWithMessage(string|Closure $expression, Message $message, array $additionalContext = []): mixed;

    /**
     * Evaluates Symfony expression with given context variables, or executes given Closure binding its parameters to context variables by name.
     */
    public function evaluateWithContext(string|Closure $expression, array $context): mixed;
}
