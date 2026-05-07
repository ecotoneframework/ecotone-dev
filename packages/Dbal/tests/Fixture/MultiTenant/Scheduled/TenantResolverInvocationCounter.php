<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class TenantResolverInvocationCounter
{
    private int $count = 0;

    #[Before(pointcut: WithTenantResolver::class)]
    public function increment(): void
    {
        $this->count++;
    }

    #[QueryHandler('counter.invocations')]
    public function invocations(): int
    {
        return $this->count;
    }
}
