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
            name: 'repository finds ' . ExecutionRecordingTracer::shortClassOf($aggregateClassName),
            call: fn () => $this->repository->findBy($aggregateClassName, $identifiers),
            outcome: fn (?ResolvedAggregate $aggregate): ExecutionHint => $aggregate === null ? ExecutionHint::shownInLines(ExecutionHint::FOUND, 'nothing') : ExecutionHint::detail(ExecutionHint::FOUND, 'version ' . ($aggregate->getVersionBeforeHandling() ?? 'none')),
            hints: [ExecutionHint::detail(ExecutionHint::IDENTIFIERS, ExecutionHint::json($identifiers))]
        );
    }

    public function save(ResolvedAggregate $aggregate, array $metadata): ?int
    {
        return $this->recorded(
            name: 'repository saves ' . ExecutionRecordingTracer::shortClassOf($aggregate->getAggregateClassName()) . ($aggregate->getEvents() === [] ? '' : ' with ' . count($aggregate->getEvents()) . ' events'),
            call: fn () => $this->repository->save($aggregate, $metadata),
            outcome: fn (?int $version): string => $version === null ? '' : 'at version ' . $version,
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
            $described = $step === null ? '' : $outcome($result);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $described instanceof ExecutionHint ? $this->tracer->endStep(step: $step, outcome: '', hints: [$described]) : $this->tracer->endStep($step, $described);
        }

        return $result;
    }
}
