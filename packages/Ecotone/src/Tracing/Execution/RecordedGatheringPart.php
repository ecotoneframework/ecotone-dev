<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\Gathering\GatheringHeaders;
use Ecotone\Messaging\MessageHandler;

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
        $group = $headers->containsKey(GatheringHeaders::GROUP_ID) ? ' of gathering group ' . $headers->get(GatheringHeaders::GROUP_ID) : '';
        $this->tracer->recordStep(match (true) {
            $group === '' => 'read no gathering group from the header ' . GatheringHeaders::GROUP_ID,
            $headers->containsKey(GatheringHeaders::MANUAL_RELEASE) => 'read a manual release' . $group . ' from the headers ' . GatheringHeaders::GROUP_ID . ' and ' . GatheringHeaders::MANUAL_RELEASE,
            $headers->containsKey(GatheringHeaders::TIMEOUT) => 'read a timeout' . $group . ' from the headers ' . GatheringHeaders::GROUP_ID . ' and ' . GatheringHeaders::TIMEOUT,
            default => 'read part ' . ($headers->containsKey(GatheringHeaders::PART_NUMBER) ? $headers->get(GatheringHeaders::PART_NUMBER) : '?') . ' of ' . ($headers->containsKey(GatheringHeaders::SIZE) ? $headers->get(GatheringHeaders::SIZE) : '?') . $group
                . ' from the headers ' . GatheringHeaders::GROUP_ID . ', ' . GatheringHeaders::PART_NUMBER . ' and ' . GatheringHeaders::SIZE,
        });
        $this->gatheringHandler->handle($message);
    }

    public function __toString(): string
    {
        return (string) $this->gatheringHandler;
    }
}
