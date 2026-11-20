<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Messaging\Execution\CopiedToChildExecution;

/**
 * licence Apache-2.0
 */
final class ExecutionRecordingState implements CopiedToChildExecution
{
    /** @var list<ExecutionStep> */
    public array $openSteps = [];
    /** @var array<int, true> */
    public array $stepsWithChildren = [];
    /** @var list<Span|ExecutionStep> */
    public array $stepsOfOpenEntry = [];
    public bool $spanStartedInOpenEntry = false;
    /** @var ?array{messageId: string, name: string, notes: list<array{depth: int, name: string, hints?: list<ExecutionHint>}>, attributes: array<string, scalar>, remoteParent: ?SpanContext} */
    public ?array $awaitedReceive = null;
    public ?ExecutionStep $receiveEntry = null;
    public ?ExecutionStep $receiveContinuation = null;
    public bool $invocationInOpenEntry = false;
    public bool $housekeepingInOpenEntry = false;
    /** @var array<string, array{depth: int, collected: array<string, true>}> */
    public array $collectors = [];
    /** @var array<int, list<ExecutionHint>> */
    public array $hintsOfOpenSteps = [];
    /** @var list<?string> */
    public array $flowTestingFailures = [];
    /** @var list<?array<string, mixed>> */
    public array $suspendedEntries = [];
    public int $lastCoroutineStartedBeforeTheOpenEntry = 0;
    public ?int $orderOfTheStepThatStartedThisCoroutine = null;

    public function copyForChildExecution(int $childCoroutineId): ?object
    {
        $innermostOpenStep = $this->openSteps[count($this->openSteps) - 1] ?? null;
        if ($innermostOpenStep === null || $childCoroutineId <= $this->lastCoroutineStartedBeforeTheOpenEntry) {
            return null;
        }
        $copy = new self();
        $copy->orderOfTheStepThatStartedThisCoroutine = (int) $innermostOpenStep->attributes[SpanAttribute::EXECUTION_ORDER];

        return $copy;
    }
}
