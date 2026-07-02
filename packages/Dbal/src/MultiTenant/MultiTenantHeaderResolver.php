<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\ClosureExpression\ExecutorFor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Enterprise
 */
final class MultiTenantHeaderResolver
{
    public function __construct(
        private string $tenantHeaderName,
    ) {
    }

    public function resolve(Message $message, #[ExecutorFor(WithTenantResolver::class)] ?AttributeExpressionExecutor $tenantResolver = null): array
    {
        if ($tenantResolver === null) {
            return [];
        }
        if ($message->getHeaders()->containsKey($this->tenantHeaderName)) {
            return [];
        }

        $value = $tenantResolver->execute($message);

        if ($value === null) {
            return [];
        }

        if (! is_string($value) && ! is_int($value)) {
            $type = is_object($value) ? $value::class : gettype($value);
            throw InvalidArgumentException::create(sprintf(
                'WithTenantResolver expression for tenant header "%s" must evaluate to string|int|null, got %s',
                $this->tenantHeaderName,
                $type
            ));
        }

        return [$this->tenantHeaderName => $value];
    }
}
