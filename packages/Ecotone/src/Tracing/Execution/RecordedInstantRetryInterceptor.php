<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker;
use Ecotone\Modelling\Config\InstantRetry\InstantRetryInterceptor;
use Ecotone\Modelling\Config\InstantRetry\RetryStatusTracker;

/**
 * licence Apache-2.0
 */
final class RecordedInstantRetryInterceptor extends InstantRetryInterceptor
{
    /**
     * @param array<class-string> $exceptions
     */
    public function __construct(
        int $maxRetryAttempts,
        array $exceptions,
        RetryStatusTracker $retryStatusTracker,
        TransactionStatusTracker $transactionStatusTracker,
        ?string $relatedEndpointId,
        private ExecutionRecordingTracer $tracer,
    ) {
        parent::__construct(maxRetryAttempts: $maxRetryAttempts, exceptions: $exceptions, retryStatusTracker: $retryStatusTracker, transactionStatusTracker: $transactionStatusTracker, relatedEndpointId: $relatedEndpointId);
    }

    public function retry(MethodInvocation $methodInvocation, Message $message, #[Reference] LoggingGateway $logger, ?PollingMetadata $pollingMetadata)
    {
        return parent::retry(new RecordedRetryAttempt($methodInvocation, new RetryAttempts(), $this->tracer), $message, $logger, $pollingMetadata);
    }
}
