<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;

use Closure;

use function count;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Execution\ExecutionList;
use Ecotone\Messaging\Execution\ExecutionScope;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ReplyRecording
{
    public const OUTPUT_CHANNEL = 'output channel';
    public const ROUTING_SLIP = 'routing slip';


    public function __construct(private string $handler, private ExecutionRecordingTracer $tracer)
    {
    }

    public function started(Message $request): void
    {
        $this->openRequests()->items[] = ['request' => $request, 'reply' => null, 'handedOver' => false];
    }

    public function replied(?Message $reply): void
    {
        if ($this->openRequests()->items === []) {
            return;
        }
        $this->openRequests()->items[count($this->openRequests()->items) - 1]['reply'] = $reply;
    }

    public function routedTo(string $mechanism, string $channelName, Message $forwarded, Closure $send): void
    {
        $position = count($this->openRequests()->items) - 1;
        if ($position < 0) {
            $send();

            return;
        }
        $this->openRequests()->items[$position]['handedOver'] = true;
        $request = $this->openRequests()->items[$position]['request'];
        $reply = $this->openRequests()->items[$position]['reply'] ?? $request;
        $step = $this->tracer->startStep('forward to ' . $channelName, hints: array_values(array_filter([
            ...($mechanism === self::OUTPUT_CHANNEL
                ? [ExecutionHint::shownInLines(ExecutionHint::CHOSEN_BY, 'output channel of ' . $this->handler), self::routingSlipChangeOf($request, $forwarded)]
                : self::takenFromTheRoutingSlip(request: $request, received: $reply, forwarded: $forwarded)),
            $forwarded->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL) ? ExecutionHint::detail(ExecutionHint::KEPT, 'reply channel of the request') : null,
            ...self::forwardedOf($request, $forwarded),
        ])));
        try {
            $send();
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

    public function completedAsPart(): void
    {
        if ($this->openRequests()->items === []) {
            return;
        }
        $this->openRequests()->items[count($this->openRequests()->items) - 1]['handedOver'] = true;
    }

    public function finished(Message $request, bool $failed): void
    {
        $open = array_pop($this->openRequests()->items);
        if ($failed || $open === null || $open['handedOver']) {
            return;
        }
        if ($open['reply'] === null) {
            $this->tracer->recordStep('no hand-over', hints: [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'nothing returned by ' . $this->handler)]);

            return;
        }
        $payload = ExecutionRecorder::typeOf($open['reply']->getPayload());
        $slip = self::routingSlipChangeOf($request, $open['reply']);
        if ($request->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)) {
            $this->tracer->recordStep('reply to the reply channel of the request', hints: array_values(array_filter([$slip, ExecutionHint::detail(ExecutionHint::PAYLOAD, $payload)])));

            return;
        }
        $this->tracer->recordStep('no hand-over', hints: array_values(array_filter([ExecutionHint::shownInLines(ExecutionHint::OUTCOME, $payload . ' returned by ' . $this->handler . ', not taken'), $slip])));
    }

    /**
     * @return list<?ExecutionHint>
     */
    private static function takenFromTheRoutingSlip(Message $request, Message $received, Message $forwarded): array
    {
        $remaining = array_slice(self::routingSlipOf($received), 1);
        $addedByTheHandler = $request === $received ? null : self::routingSlipChangeOf($request, $received);

        return [
            ExecutionHint::shownInLines(ExecutionHint::CHOSEN_BY, 'routing slip' . (self::routingSlipOf($forwarded) !== $remaining ? '' : ($remaining === [] ? ', its last entry' : ', next: ' . implode(', ', $remaining)))),
            $addedByTheHandler?->key === ExecutionHint::ROUTING_SLIP ? $addedByTheHandler : null,
        ];
    }

    private static function routingSlipChangeOf(Message $received, Message $handedOver): ?ExecutionHint
    {
        return self::routingSlipChange(before: self::routingSlipOf($received), after: self::routingSlipOf($handedOver));
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    private static function routingSlipChange(array $before, array $after): ?ExecutionHint
    {
        $added = array_slice($after, 0, max(0, count($after) - count($before)));
        $dropped = array_slice($before, 0, max(0, count($before) - count($after)));

        return match (true) {
            $before === [] && $after === [] => null,
            $before === $after => ExecutionHint::shownInLines(ExecutionHint::KEPT, 'routing slip ' . implode(', ', $after)),
            $added !== [] && array_slice($after, count($added)) === $before => ExecutionHint::shownInLines(ExecutionHint::ROUTING_SLIP, 'adds ' . implode(', ', $added)),
            $dropped !== [] && array_slice($before, count($dropped)) === $after => ExecutionHint::shownInLines(ExecutionHint::ROUTING_SLIP, 'drops ' . implode(', ', $dropped)),
            default => ExecutionHint::shownInLines(ExecutionHint::ROUTING_SLIP, 'replaces ' . implode(', ', $before) . ' with ' . implode(', ', $after)),
        };
    }

    /**
     * @return list<ExecutionHint>
     */
    private static function forwardedOf(Message $request, Message $forwarded): array
    {
        if ($request === $forwarded) {
            return [];
        }
        $requestHeaders = $request->getHeaders()->headers();
        $forwardedHeaders = $forwarded->getHeaders()->headers();
        unset($requestHeaders[MessageHeaders::ROUTING_SLIP], $forwardedHeaders[MessageHeaders::ROUTING_SLIP]);
        $hints = [ExecutionHint::detail(ExecutionHint::PAYLOAD, ExecutionRecorder::typeOf($forwarded->getPayload()))];
        foreach ([ExecutionHint::HEADERS_ADDED => array_keys(array_diff_key($forwardedHeaders, $requestHeaders)), ExecutionHint::HEADERS_REMOVED => array_keys(array_diff_key($requestHeaders, $forwardedHeaders))] as $key => $names) {
            if ($names !== []) {
                $hints[] = ExecutionHint::detail($key, implode(', ', $names));
            }
        }

        return $hints;
    }

    /**
     * @return list<string>
     */
    private static function routingSlipOf(Message $message): array
    {
        $headers = $message->getHeaders();
        $slip = $headers->containsKey(MessageHeaders::ROUTING_SLIP) ? (string) $headers->get(MessageHeaders::ROUTING_SLIP) : '';

        return $slip === '' ? [] : explode(',', $slip);
    }

    private function openRequests(): ExecutionList
    {
        return ExecutionScope::stateOf($this, static fn () => new ExecutionList());
    }
}
