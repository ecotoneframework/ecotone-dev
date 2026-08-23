<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class ExpressionDeduplicationCommandHandler
{
    private int $called = 0;

    #[Deduplicated(expression: "headers['orderId']")]
    #[CommandHandler('expression_deduplication.handle_with_header_expression')]
    public function handleWithHeaderExpression(): void
    {
        $this->called++;
    }

    #[Deduplicated(expression: 'payload')]
    #[CommandHandler('expression_deduplication.handle_with_payload_expression')]
    public function handleWithPayloadExpression(): void
    {
        $this->called++;
    }

    #[Deduplicated(expression: "headers['customerId'] ~ '_' ~ payload")]
    #[CommandHandler('expression_deduplication.handle_with_complex_expression')]
    public function handleWithComplexExpression(): void
    {
        $this->called++;
    }

    #[Deduplicated(expression: "headers['orderId']")]
    #[Asynchronous('async_expression')]
    #[CommandHandler('expression_deduplication.handle_async_with_expression', endpointId: 'expression_deduplication.async.endpoint')]
    public function handleAsyncWithExpression(): void
    {
        $this->called++;
    }

    #[QueryHandler('expression_deduplication.getCallCount')]
    public function getCallCount(): int
    {
        return $this->called;
    }

    #[QueryHandler('expression_deduplication.reset')]
    public function reset(): void
    {
        $this->called = 0;
    }
}
