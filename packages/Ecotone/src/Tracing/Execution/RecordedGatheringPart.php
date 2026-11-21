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
        $header = fn (string $name): string => $headers->containsKey($name) ? (string) $headers->get($name) : '?';
        [$label, $readFrom] = match (true) {
            ! $headers->containsKey(GatheringHeaders::GROUP_ID) => ['read no gathering group', [GatheringHeaders::GROUP_ID]],
            $headers->containsKey(GatheringHeaders::MANUAL_RELEASE) => ['read manual release', [GatheringHeaders::GROUP_ID, GatheringHeaders::MANUAL_RELEASE]],
            $headers->containsKey(GatheringHeaders::TIMEOUT) => ['read timeout', [GatheringHeaders::GROUP_ID, GatheringHeaders::TIMEOUT]],
            default => ['read part ' . $header(GatheringHeaders::PART_NUMBER) . ' of ' . $header(GatheringHeaders::SIZE), [GatheringHeaders::GROUP_ID, GatheringHeaders::PART_NUMBER, GatheringHeaders::SIZE]],
        };
        $this->tracer->recordStep($label, hints: array_values(array_filter([
            $headers->containsKey(GatheringHeaders::GROUP_ID) ? ExecutionHint::detail(ExecutionHint::GROUP, $header(GatheringHeaders::GROUP_ID)) : null,
            ExecutionHint::detail(ExecutionHint::HEADERS_READ, implode(', ', $readFrom)),
        ])));
        $this->gatheringHandler->handle($message);
    }

    public function __toString(): string
    {
        return (string) $this->gatheringHandler;
    }
}
