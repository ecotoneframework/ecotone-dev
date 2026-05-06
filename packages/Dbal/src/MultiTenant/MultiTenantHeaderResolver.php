<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class MultiTenantHeaderResolver
{
    public function __construct(
        private string $tenantHeaderName,
        private ExpressionEvaluationService $expressionEvaluationService,
    ) {
    }

    public function resolve(Message $message, ?WithTenantResolver $config = null): array
    {
        if ($config === null) {
            return [];
        }
        if ($message->getHeaders()->containsKey($this->tenantHeaderName)) {
            return [];
        }

        $value = $this->expressionEvaluationService->evaluate(
            $config->getExpression(),
            [
                'payload' => $message->getPayload(),
                'headers' => $message->getHeaders()->headers(),
            ]
        );

        if ($value === null) {
            return [];
        }

        if (! is_string($value) && ! is_int($value)) {
            $type = is_object($value) ? $value::class : gettype($value);
            throw InvalidArgumentException::create(sprintf(
                'WithTenantResolver expression for tenant header "%s" must evaluate to string|int|null, got %s. Expression: %s',
                $this->tenantHeaderName,
                $type,
                $config->getExpression()
            ));
        }

        return [$this->tenantHeaderName => $value];
    }
}
