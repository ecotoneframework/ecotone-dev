<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\EventSourcing\Event;
use Ecotone\Projecting\ProjectorExecutor;

/**
 * licence Apache-2.0
 */
final class RecordedProjectorExecutor implements ProjectorExecutor
{
    public function __construct(private ProjectorExecutor $projectorExecutor, private ExecutionRecordingTracer $tracer)
    {
    }

    public function project(Event $event, mixed $userState = null, bool $isRebuilding = false): mixed
    {
        return StepAround::of(tracer: $this->tracer, name: 'project ' . ExecutionRecordingTracer::shortClassOf($event->eventName), call: fn () => $this->projectorExecutor->project(event: $event, userState: $userState, isRebuilding: $isRebuilding));
    }

    public function init(): void
    {
        StepAround::of(tracer: $this->tracer, name: 'init the projection', call: fn () => $this->projectorExecutor->init());
    }

    public function delete(): void
    {
        StepAround::of(tracer: $this->tracer, name: 'delete the projection', call: fn () => $this->projectorExecutor->delete());
    }

    public function flush(mixed $userState, bool $isRebuilding): void
    {
        StepAround::of(tracer: $this->tracer, name: 'flush the projection', call: fn () => $this->projectorExecutor->flush($userState, $isRebuilding));
    }

    public function reset(?string $partitionKey = null): void
    {
        StepAround::of(tracer: $this->tracer, name: 'reset the projection' . ($partitionKey === null ? '' : ' partition ' . $partitionKey), call: fn () => $this->projectorExecutor->reset($partitionKey));
    }
}
