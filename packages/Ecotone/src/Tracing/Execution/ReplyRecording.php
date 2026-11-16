<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;

use Closure;

use function count;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ReplyRecording
{
    public const OUTPUT_CHANNEL = 'output channel';
    public const ROUTING_SLIP = 'routing slip';

    /** @var list<array{request: Message, reply: ?Message, handedOver: bool}> */
    private array $openRequests = [];

    public function __construct(private string $handler, private ExecutionRecordingTracer $tracer)
    {
    }

    public function started(Message $request): void
    {
        $this->openRequests[] = ['request' => $request, 'reply' => null, 'handedOver' => false];
    }

    public function replied(?Message $reply): void
    {
        $this->openRequests[count($this->openRequests) - 1]['reply'] = $reply;
    }

    public function routedTo(string $mechanism, string $channelName, Message $forwarded, Closure $send): void
    {
        $position = count($this->openRequests) - 1;
        $this->openRequests[$position]['handedOver'] = true;
        $request = $this->openRequests[$position]['request'];
        $reply = $this->openRequests[$position]['reply'] ?? $request;
        $step = $this->tracer->startStep('forward to ' . $channelName, hints: array_values(array_filter([
            ExecutionHint::critical(ExecutionHint::CHOSEN_BY, $mechanism === self::OUTPUT_CHANNEL ? 'output channel of ' . $this->handler : 'routing slip'),
            self::routingSlipChangeOf($mechanism === self::ROUTING_SLIP ? $reply : $request, $forwarded),
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
        $this->openRequests[count($this->openRequests) - 1]['handedOver'] = true;
    }

    public function finished(Message $request, bool $failed): void
    {
        $open = array_pop($this->openRequests);
        if ($failed || $open === null || $open['handedOver']) {
            return;
        }
        if ($open['reply'] === null) {
            $this->tracer->recordStep('no hand-over', hints: [ExecutionHint::critical(ExecutionHint::RESULT, 'nothing returned by ' . $this->handler)]);

            return;
        }
        $payload = ExecutionRecorder::typeOf($open['reply']->getPayload());
        $slip = self::routingSlipChangeOf($request, $open['reply']);
        if ($request->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)) {
            $this->tracer->recordStep('reply to the reply channel of the request', hints: array_values(array_filter([ExecutionHint::critical(ExecutionHint::CHOSEN_BY, 'reply channel of the request'), $slip, ExecutionHint::detail(ExecutionHint::PAYLOAD, $payload)])));

            return;
        }
        $this->tracer->recordStep('no hand-over', hints: array_values(array_filter([ExecutionHint::critical(ExecutionHint::RESULT, $payload . ' returned by ' . $this->handler . ', nothing takes it'), $slip])));
    }

    private static function routingSlipChangeOf(Message $request, Message $handedOver): ?ExecutionHint
    {
        $before = self::routingSlipOf($request);
        $after = self::routingSlipOf($handedOver);
        if ($before === [] && $after === []) {
            return null;
        }
        if ($before === $after) {
            return ExecutionHint::critical(ExecutionHint::KEPT, 'routing slip ' . implode(', ', $after));
        }

        return ExecutionHint::critical(ExecutionHint::ROUTING_SLIP, ($before === [] ? 'none' : implode(', ', $before)) . ' → ' . ($after === [] ? 'none' : implode(', ', $after)));
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
}
