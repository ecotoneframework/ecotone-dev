<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Handler\ClosureExpression\ClosureExpressionInvoker;
use Ecotone\Messaging\Handler\ClosureExpression\InvokerFor;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Enterprise
 */
final class MultiTenantHeaderResolver
{
    public function __construct(
        private string $tenantHeaderName,
        private ExpressionEvaluationService $expressionEvaluationService,
    ) {
    }

    public function resolve(Message $message, ?WithTenantResolver $config = null, #[InvokerFor(WithTenantResolver::class)] ?ClosureExpressionInvoker $tenantInvoker = null): array
    {
        if ($config === null) {
            return [];
        }
        if ($message->getHeaders()->containsKey($this->tenantHeaderName)) {
            return [];
        }

        $expression = $config->getExpression();
        $value = $tenantInvoker !== null
            ? $tenantInvoker->invoke($message)
            : $this->expressionEvaluationService->evaluateWithMessage($expression, $message);

        if ($value === null) {
            return [];
        }

        if (! is_string($value) && ! is_int($value)) {
            $type = is_object($value) ? $value::class : gettype($value);
            throw InvalidArgumentException::create(sprintf(
                'WithTenantResolver expression for tenant header "%s" must evaluate to string|int|null, got %s. Expression: %s',
                $this->tenantHeaderName,
                $type,
                is_string($expression) ? $expression : 'closure'
            ));
        }

        return [$this->tenantHeaderName => $value];
    }
}
