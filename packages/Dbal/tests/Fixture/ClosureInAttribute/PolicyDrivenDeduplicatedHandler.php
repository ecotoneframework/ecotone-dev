<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class PolicyDrivenDeduplicatedHandler
{
    private array $handledPerCustomer = [];
    private array $handledPerOrder = [];

    #[DedupPolicy(scope: 'customer')]
    #[Deduplicated(expression: static function (DedupPolicy $policy, #[Header('customerId')] string $customerId, #[Header('orderId')] string $orderId): string {
        return $policy->scope === 'customer' ? $customerId : $orderId;
    })]
    #[CommandHandler('policyDedup.perCustomer', endpointId: 'policyDedupPerCustomerEndpoint')]
    public function handlePerCustomer(#[Header('orderId')] string $orderId): void
    {
        $this->handledPerCustomer[] = $orderId;
    }

    #[DedupPolicy(scope: 'order')]
    #[Deduplicated(expression: static function (DedupPolicy $policy, #[Header('customerId')] string $customerId, #[Header('orderId')] string $orderId): string {
        return $policy->scope === 'customer' ? $customerId : $orderId;
    })]
    #[CommandHandler('policyDedup.perOrder', endpointId: 'policyDedupPerOrderEndpoint')]
    public function handlePerOrder(#[Header('orderId')] string $orderId): void
    {
        $this->handledPerOrder[] = $orderId;
    }

    #[QueryHandler('policyDedup.handledPerCustomer')]
    public function handledPerCustomer(): array
    {
        return $this->handledPerCustomer;
    }

    #[QueryHandler('policyDedup.handledPerOrder')]
    public function handledPerOrder(): array
    {
        return $this->handledPerOrder;
    }
}
