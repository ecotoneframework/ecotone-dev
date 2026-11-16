<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\Gathering\GatheringHeaders;
use Ecotone\Messaging\MessageHandler;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedGatheringPart implements MessageHandler
{
    public function __construct(private MessageHandler $gatheringHandler, private ExecutionRecordingTracer $tracer)
    {
    }

    public function handle(Message $message): void
    {
        $headers = $message->getHeaders();
        $step = $this->tracer->startStep(match (true) {
            $headers->containsKey(GatheringHeaders::MANUAL_RELEASE) => 'release requested manually',
            $headers->containsKey(GatheringHeaders::TIMEOUT) => 'release requested by the timeout',
            $headers->containsKey(GatheringHeaders::PART_NUMBER) && $headers->containsKey(GatheringHeaders::SIZE) => 'store part ' . $headers->get(GatheringHeaders::PART_NUMBER) . ' of ' . $headers->get(GatheringHeaders::SIZE),
            default => 'gather a message that names no part of a gathering group',
        });
        try {
            $this->gatheringHandler->handle($message);
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
        return (string) $this->gatheringHandler;
    }
}
