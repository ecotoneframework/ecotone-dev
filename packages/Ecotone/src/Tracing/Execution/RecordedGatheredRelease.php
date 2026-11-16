<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedGatheredRelease implements MessageHandler
{
    public function __construct(private MessageHandler $gatheredHandler, private ExecutionRecordingTracer $tracer)
    {
    }

    public function handle(Message $message): void
    {
        $parts = $message->getPayload();
        $step = $parts instanceof GatheredParts
            ? $this->tracer->startStep('release group', hints: [
                ExecutionHint::critical(ExecutionHint::CHOSEN_BY, match ($parts->releaseReason) {
                    GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR => 'all parts accounted for',
                    GatheredReleaseReason::TIMEOUT => 'timeout',
                    GatheredReleaseReason::MANUAL_RELEASE => 'manual release',
                }),
                ExecutionHint::critical(ExecutionHint::RESULT, 'GatheredParts, ' . ($parts->size - count($parts->missingPartNumbers)) . ' of ' . $parts->size . ' parts, original payload ' . ExecutionRecorder::typeOf($parts->originalPayload)),
                ExecutionHint::critical(ExecutionHint::RUNS, 'this consumer run, no queue, straight to the #[Gathered] handler'),
            ])
            : null;
        try {
            $this->gatheredHandler->handle($message);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, '');
        }
    }

    public function __toString(): string
    {
        return (string) $this->gatheredHandler;
    }
}
