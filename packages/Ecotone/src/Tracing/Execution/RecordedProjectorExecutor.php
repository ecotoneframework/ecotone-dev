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
        return $this->projectorExecutor->project($event, $userState, $isRebuilding);
    }

    public function init(): void
    {
        RecordedStep::of($this->tracer, 'init the projection', fn () => $this->projectorExecutor->init());
    }

    public function delete(): void
    {
        RecordedStep::of($this->tracer, 'delete the projection', fn () => $this->projectorExecutor->delete());
    }

    public function flush(mixed $userState, bool $isRebuilding): void
    {
        RecordedStep::of($this->tracer, 'flush the projection', fn () => $this->projectorExecutor->flush($userState, $isRebuilding));
    }

    public function reset(?string $partitionKey = null): void
    {
        RecordedStep::of($this->tracer, 'reset the projection' . ($partitionKey === null ? '' : ' partition ' . $partitionKey), fn () => $this->projectorExecutor->reset($partitionKey));
    }
}
