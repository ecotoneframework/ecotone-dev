<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use function count;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * licence Apache-2.0
 */
final class TracedEventStore implements EventStore
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly TracerProviderInterface $tracerProvider,
    ) {
    }

    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void
    {
        $this->eventStore->create($streamName, $streamEvents, $streamMetadata);
    }

    public function appendTo(string $streamName, array $streamEvents, ?AppendCondition $appendCondition = null): void
    {
        if ($appendCondition === null || ! $appendCondition->hasTagCondition()) {
            $this->eventStore->appendTo($streamName, $streamEvents, $appendCondition);

            return;
        }

        $span = $this->conditionalAppendSpan($streamName, $streamEvents, $appendCondition);
        $spanScope = $span->activate();

        try {
            $this->eventStore->appendTo($streamName, $streamEvents, $appendCondition);
        } catch (DecisionModelConcurrencyException $conflict) {
            $span->addEvent(DynamicConsistencyBoundarySpanAttributes::CONFLICT_EVENT_NAME, $conflict->conflictFields());
            $span->recordException($conflict);
            self::closeSpan($span, $spanScope, StatusCode::STATUS_ERROR, $conflict->getMessage());

            throw $conflict;
        } catch (Throwable $exception) {
            $span->recordException($exception);
            self::closeSpan($span, $spanScope, StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        }

        self::closeSpan($span, $spanScope, StatusCode::STATUS_OK, null);
    }

    public function delete(string $streamName): void
    {
        $this->eventStore->delete($streamName);
    }

    public function hasStream(string $streamName): bool
    {
        return $this->eventStore->hasStream($streamName);
    }

    public function load(
        string $streamName,
        int $fromNumber = 1,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null,
        bool $deserialize = true
    ): iterable {
        return $this->eventStore->load($streamName, $fromNumber, $count, $metadataMatcher, $deserialize);
    }

    public function loadAggregateEvents(
        string $streamName,
        ?string $aggregateType,
        string $aggregateId,
        int $fromVersion = 1,
        ?int $count = null,
        array $eventNames = [],
        bool $deserialize = true
    ): iterable {
        return $this->eventStore->loadAggregateEvents($streamName, $aggregateType, $aggregateId, $fromVersion, $count, $eventNames, $deserialize);
    }

    public function loadByCriteria(EventCriteria $criteria): LoadedEvents
    {
        return $this->eventStore->loadByCriteria($criteria);
    }

    /**
     * @param object[]|array[] $streamEvents
     */
    private function conditionalAppendSpan(string $streamName, array $streamEvents, AppendCondition $appendCondition): SpanInterface
    {
        return $this->tracerProvider
            ->getTracer(EcotoneSpanBuilder::ECOTONE_TRACER_NAME)
            ->spanBuilder('Conditional Append: ' . $streamName)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(DynamicConsistencyBoundarySpanAttributes::TAGS, DynamicConsistencyBoundarySpanAttributes::userTags($appendCondition->expectedTagVersions()))
            ->setAttribute(DynamicConsistencyBoundarySpanAttributes::AGGREGATES, DynamicConsistencyBoundarySpanAttributes::aggregates($appendCondition->expectedTagVersions()))
            ->setAttribute(DynamicConsistencyBoundarySpanAttributes::EVENTS_APPENDED, count($streamEvents))
            ->startSpan();
    }

    private static function closeSpan(SpanInterface $span, ScopeInterface $spanScope, string $statusCode, ?string $descriptionStatusCode): void
    {
        $span->setStatus($statusCode, $descriptionStatusCode);
        $spanScope->detach();
        $span->end();
    }
}
