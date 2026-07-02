<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ChannelSendSpan
{
    private bool $isClosed = false;

    public function __construct(private SpanInterface $span, private ScopeInterface $scope)
    {
    }

    public function closeWithSuccess(): void
    {
        $this->close(StatusCode::STATUS_OK, null);
    }

    public function closeWithFailure(Throwable $exception): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->span->recordException($exception);
        $this->close(StatusCode::STATUS_ERROR, $exception->getMessage());
    }

    private function close(string $statusCode, ?string $statusDescription): void
    {
        if ($this->isClosed) {
            return;
        }
        $this->isClosed = true;

        $this->span->setStatus($statusCode, $statusDescription);
        $this->scope->detach();
        $this->span->end();
    }
}
