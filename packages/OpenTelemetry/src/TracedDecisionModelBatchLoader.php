<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Message;
use Ecotone\Modelling\DecisionModel\DecisionModelBatchLoader;
use Ecotone\Modelling\DecisionModel\DecisionModelLoadedState;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * licence Apache-2.0
 */
final class TracedDecisionModelBatchLoader
{
    public function __construct(
        private readonly DecisionModelBatchLoader $batchLoader,
        private readonly string $handlerName,
        private readonly TracerProviderInterface $tracerProvider,
    ) {
    }

    /**
     * @return array<string, DecisionModelLoadedState>
     */
    public function load(Message $message): array
    {
        $span = EcotoneSpanBuilder::create($message, 'Decision Models: ' . $this->handlerName, $this->tracerProvider, SpanKind::KIND_INTERNAL)
            ->startSpan();
        $spanScope = $span->activate();

        try {
            $loadedState = $this->batchLoader->load($message);
        } catch (Throwable $exception) {
            $span->recordException($exception);
            self::closeSpan($span, $spanScope, StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        }

        self::describeLoad($span, $loadedState[DecisionModelLoadedState::HEADER_NAME]);
        self::closeSpan($span, $spanScope, StatusCode::STATUS_OK, null);

        return $loadedState;
    }

    private static function describeLoad(SpanInterface $span, DecisionModelLoadedState $loadedState): void
    {
        $capturedTagVersions = $loadedState->capturedTagVersions();

        $span->setAttribute(DynamicConsistencyBoundarySpanAttributes::MODELS, DynamicConsistencyBoundarySpanAttributes::classList($loadedState->loadedModelClassNames()));
        $span->setAttribute(DynamicConsistencyBoundarySpanAttributes::TAGS, DynamicConsistencyBoundarySpanAttributes::userTags($capturedTagVersions));
        $span->setAttribute(DynamicConsistencyBoundarySpanAttributes::CAPTURED_VERSIONS, DynamicConsistencyBoundarySpanAttributes::capturedVersions($capturedTagVersions));
        $span->setAttribute(DynamicConsistencyBoundarySpanAttributes::AGGREGATES, DynamicConsistencyBoundarySpanAttributes::aggregates($capturedTagVersions));
        $span->setAttribute(DynamicConsistencyBoundarySpanAttributes::EVENTS_FOLDED, $loadedState->foldedEventCount());
    }

    private static function closeSpan(SpanInterface $span, ScopeInterface $spanScope, string $statusCode, ?string $descriptionStatusCode): void
    {
        $span->setStatus($statusCode, $descriptionStatusCode);
        $spanScope->detach();
        $span->end();
    }
}
