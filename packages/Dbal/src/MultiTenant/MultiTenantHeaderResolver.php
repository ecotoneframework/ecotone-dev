<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant;

use Ecotone\Api\Dbal\Attribute\WithTenantResolver;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\ClosureExpression\ExecutorFor;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
