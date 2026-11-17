<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Handler\Gathering\GatheringHeaders;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedPartReportChannel implements MessageChannel
{
    public function __construct(private MessageChannel $messageChannel, private string $channelName, private ExecutionRecordingTracer $tracer)
    {
    }

    public function send(Message $message): void
    {
        $headers = $message->getHeaders();
        $header = fn (string $name): mixed => $headers->containsKey($name) ? $headers->get($name) : '?';
        $step = $this->tracer->startStep('part report to ' . $this->channelName, hints: [
            ExecutionHint::critical(ExecutionHint::CHOSEN_BY, 'header ' . GatheringHeaders::GROUP_ID),
            ExecutionHint::critical(ExecutionHint::OUTCOME, 'part ' . $header(GatheringHeaders::PART_NUMBER) . ' of ' . $header(GatheringHeaders::SIZE) . ', ' . ($headers->containsKey(GatheringHeaders::NO_RESULT) && $headers->get(GatheringHeaders::NO_RESULT) ? 'no reply' : 'reply ' . ExecutionRecorder::typeOf($message->getPayload()))),
        ]);
        try {
            $this->messageChannel->send($message);
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
        return (string) $this->messageChannel;
    }
}
