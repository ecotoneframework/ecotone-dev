<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;

/**
 * licence Apache-2.0
 */
final class RecordedStreamSource implements StreamSource
{
    public function __construct(private StreamSource $streamSource, private ExecutionRecordingTracer $tracer)
    {
    }

    public function canHandle(string $projectionName): bool
    {
        return $this->streamSource->canHandle($projectionName);
    }

    public function load(string $projectionName, ?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        return RecordedStep::of(
            $this->tracer,
            'load events of ' . $projectionName . ($partitionKey === null ? '' : ' partition ' . $partitionKey),
            fn () => $this->streamSource->load($projectionName, $lastPosition, $count, $partitionKey),
            fn (StreamPage $page): ExecutionHint => $page->events === [] ? ExecutionHint::critical(ExecutionHint::FOUND, '0 events') : ExecutionHint::detail(ExecutionHint::FOUND, count($page->events) . ' events'),
            [ExecutionHint::detail(ExecutionHint::RANGE, 'after position ' . ($lastPosition ?? 'start') . ', up to ' . $count)],
        );
    }
}
