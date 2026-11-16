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
        $step = $this->tracer->startStep(
            'part report of a request ' . ($headers->get(GatheringHeaders::NO_RESULT) ? 'without a reply' : 'with a reply ' . ExecutionRecorder::typeOf($message->getPayload()))
            . ', part ' . $headers->get(GatheringHeaders::PART_NUMBER) . ' of ' . $headers->get(GatheringHeaders::SIZE) . ', to ' . $this->channelName
        );
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
