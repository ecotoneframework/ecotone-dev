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
        $position = count($this->openRequests) - 1;
        $this->openRequests[$position]['reply'] = $reply;
        if ($reply === null) {
            return;
        }
        $before = self::routingSlipOf($this->openRequests[$position]['request']);
        $after = self::routingSlipOf($reply);
        if ($before === $after) {
            return;
        }
        $this->tracer->recordStep($this->handler . match (true) {
            $after === [] => ' removed the routing slip ' . implode(', ', $before),
            $before === [] || array_slice($after, -count($before)) === $before => ' put ' . implode(', ', array_slice($after, 0, count($after) - count($before))) . ' in front of the routing slip, which is now ' . implode(', ', $after),
            default => ' changed the routing slip from ' . implode(', ', $before) . ' to ' . implode(', ', $after),
        });
    }

    public function routedTo(string $mechanism, string $channelName, Message $forwarded, Closure $send): void
    {
        $position = count($this->openRequests) - 1;
        $this->openRequests[$position]['handedOver'] = true;
        $request = $this->openRequests[$position]['request'];
        $reply = $this->openRequests[$position]['reply'];
        $payload = ExecutionRecorder::typeOf($forwarded->getPayload());
        $slipBefore = $reply === null ? [] : self::routingSlipOf($reply);
        $slipAfter = self::routingSlipOf($forwarded);
        $left = array_values(array_filter([
            $mechanism === self::OUTPUT_CHANNEL && $slipAfter !== [] ? 'the routing slip ' . implode(', ', $slipAfter) . ' stays on the message for a later step' : null,
            $forwarded->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL) ? 'the reply channel of the request stays on the message' : null,
        ]));
        $step = $this->tracer->startStep(
            ($mechanism === self::OUTPUT_CHANNEL
                ? 'forward ' . $payload . ' by the output channel ' . $channelName . ', configured on ' . $this->handler
                : 'forward ' . $payload . ' by the routing slip, taking ' . $channelName . ' from it; the slip held ' . implode(', ', $slipBefore) . ($slipAfter === [] ? ' and is now removed' : ' and now holds ' . implode(', ', $slipAfter)))
            . ($left === [] ? '' : '; ' . implode('; ', $left))
        );
        $this->tracer->recordStep(self::forwardedOf($request, $forwarded));
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
            $this->tracer->recordStep('nothing handed over: the handler returned nothing');

            return;
        }
        $payload = ExecutionRecorder::typeOf($open['reply']->getPayload());
        $this->tracer->recordStep($request->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)
            ? 'reply ' . $payload . ' to the reply channel of the request, where its sender waits for it'
            : 'nothing handed over: the handler returned ' . $payload . ', and no output channel, routing slip or reply channel of the request takes it');
    }

    private static function forwardedOf(Message $request, Message $forwarded): string
    {
        if ($request === $forwarded) {
            return 'the forwarded message is the request itself';
        }
        $requestHeaders = $request->getHeaders()->headers();
        $forwardedHeaders = $forwarded->getHeaders()->headers();
        unset($requestHeaders[MessageHeaders::ROUTING_SLIP], $forwardedHeaders[MessageHeaders::ROUTING_SLIP]);
        $changes = array_filter([
            'added: ' => array_keys(array_diff_key($forwardedHeaders, $requestHeaders)),
            'removed: ' => array_keys(array_diff_key($requestHeaders, $forwardedHeaders)),
        ]);

        return 'the forwarded message carries the ' . ExecutionRecorder::typeOf($forwarded->getPayload()) . ' the handler returned, with the headers of the request'
            . implode('', array_map(fn (string $label, array $names): string => '; ' . $label . implode(', ', $names), array_keys($changes), $changes));
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
