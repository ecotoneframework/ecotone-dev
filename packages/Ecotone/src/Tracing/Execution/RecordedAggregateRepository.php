<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Closure;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\ResolvedAggregate;
use Ecotone\Modelling\Repository\AggregateRepository;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedAggregateRepository implements AggregateRepository
{
    public function __construct(private AggregateRepository $repository, private ExecutionRecordingTracer $tracer)
    {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return $this->repository->canHandle($aggregateClassName);
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?ResolvedAggregate
    {
        return $this->recorded(
            'repository finds ' . ExecutionRecordingTracer::shortClassOf($aggregateClassName),
            fn () => $this->repository->findBy($aggregateClassName, $identifiers),
            fn (?ResolvedAggregate $aggregate): string => $aggregate === null ? 'found nothing' : 'found it at version ' . ($aggregate->getVersionBeforeHandling() ?? 'none'),
            [ExecutionHint::detail(ExecutionHint::READ_FROM, ExecutionHint::json($identifiers))],
        );
    }

    public function save(ResolvedAggregate $aggregate, array $metadata): ?int
    {
        return $this->recorded(
            'repository saves ' . ExecutionRecordingTracer::shortClassOf($aggregate->getAggregateClassName()) . ($aggregate->getEvents() === [] ? '' : ' with ' . count($aggregate->getEvents()) . ' events'),
            fn () => $this->repository->save($aggregate, $metadata),
            fn (?int $version): string => $version === null ? '' : 'at version ' . $version,
        );
    }

    /**
     * @param list<ExecutionHint> $hints
     */
    private function recorded(string $name, Closure $call, Closure $outcome, array $hints = []): mixed
    {
        $step = $this->tracer->startStep($name, hints: $hints);
        try {
            $result = $call();
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, $outcome($result));
        }

        return $result;
    }
}
