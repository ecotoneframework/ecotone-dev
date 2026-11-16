<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;
use function count;

use Ecotone\Api\Messaging\BatchMessage;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Messaging\Channel\PollableChannel\SendRetries\SendRetryChannelInterceptor;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MethodInterceptor\BeforeSendChannelInterceptor;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Handler\Recoverability\DelayedRetryErrorHandler;
use Ecotone\Messaging\Support\MessageBuilder;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ChannelSendRecording
{
    public const COLLECTOR = 'MessageCollectorChannelInterceptor';
    public const PRE_SEND = 'preSend';
    public const POST_SEND = 'postSend';
    public const AFTER_SEND_COMPLETION = 'afterSendCompletion';
    public const PRE_RECEIVE = 'preReceive';
    public const POST_RECEIVE = 'postReceive';
    public const AFTER_RECEIVE_COMPLETION = 'afterReceiveCompletion';
    public const PHASES = [self::PRE_SEND, self::POST_SEND, self::AFTER_SEND_COMPLETION, self::PRE_RECEIVE, self::POST_RECEIVE, self::AFTER_RECEIVE_COMPLETION];
    private const FLOW_TESTING_NAMESPACE = 'Ecotone\\Lite\\Test\\';

    /** @var list<array{step: ?ExecutionStep, entered: list<array{step: ExecutionStep, position: int, hints: list<ExecutionHint>}>, put: ?ExecutionStep, passedOn: Message, ran: int, messageId: string, failed: ?string, failedAfter: array<int, bool>, after: list<array{position: int, phase: string, failure: ?string}>, left: bool}> */
    private array $openSends = [];
    /** @var list<string> class of each channel interceptor, in the order they run */
    private array $interceptors;

    /**
     * @param list<string|object> $interceptors class of each channel interceptor, or the interceptor when its class is known only to the container
     * @param list<list<string>> $declaredPhases phase methods each interceptor declares itself, rather than inheriting the empty default
     */
    public function __construct(
        private string $channelName,
        array $interceptors,
        private array $declaredPhases,
        private bool $onlyFlowTestingIntercepts,
        private bool $queued,
        private MessageBuilder $messageBuilder,
        private ExecutionRecordingTracer $tracer,
    ) {
        $this->interceptors = array_map(fn (string|object $interceptor): string => is_object($interceptor) ? $interceptor::class : $interceptor, $interceptors);
    }

    public function started(Message $message): void
    {
        $messageId = self::identityOf($message);
        $released = $this->tracer->releaseCollected($this->channelName, $messageId);
        $releasedInBatch = 0;
        if ($message->getPayload() instanceof BatchMessage) {
            foreach ($message->getPayload()->entries as $entry) {
                $entryHeaders = $entry['headers'] ?? [];
                $releasedInBatch += $this->tracer->releaseCollected($this->channelName, ($entryHeaders[MessageHeaders::MESSAGE_ID] ?? '') . (isset($entryHeaders[MessageHeaders::ROUTING_SLIP]) ? ' to ' . $entryHeaders[MessageHeaders::ROUTING_SLIP] : '')) ? 1 : 0;
            }
        }
        $this->openSends[] = [
            'step' => $this->onlyFlowTestingIntercepts ? null : $this->tracer->startEntry(
                match (true) {
                    $releasedInBatch > 0 => 'release ' . $releasedInBatch . ' collected messages as one batch to ',
                    $released => 'release collected message to ',
                    default => 'send ',
                } . $this->channelName,
                attributes: [
                    SpanAttribute::EXECUTION_SENT_MESSAGE => $messageId,
                    SpanAttribute::EXECUTION_HINTS => ExecutionHint::encoded(array_values(array_filter([
                        $released || $releasedInBatch > 0 ? ExecutionHint::critical(ExecutionHint::COLLECTED, 'released after the handler ended') : null,
                        self::retryOf($message),
                    ]))),
                ],
            ),
            'entered' => [],
            'put' => null,
            'passedOn' => $message,
            'ran' => 0,
            'messageId' => $messageId,
            'failed' => null,
            'failedAfter' => [],
            'after' => [],
            'left' => false,
        ];
        $this->enter(0);
    }

    public function interceptorRan(int $position, Message $passedOn): void
    {
        $current = count($this->openSends) - 1;
        $send = $this->openSends[$current];
        $entered = count($send['entered']) - 1;
        if ($entered >= 0 && $send['entered'][$entered]['position'] === $position) {
            $this->openSends[$current]['entered'][$entered]['hints'] = self::changeOf($send['passedOn'], $passedOn);
        }
        $this->openSends[$current]['passedOn'] = $passedOn;
        $this->openSends[$current]['ran'] = $position + 1;
        if ($position + 1 < count($this->interceptors)) {
            $this->enter($position + 1);

            return;
        }
        if ($send['step'] !== null) {
            $this->openSends[$current]['put'] = $this->tracer->startStep(($this->queued ? 'put on channel ' : 'deliver inline to ') . $this->channelName);
        }
    }

    public function postSentAfter(int $position): void
    {
        $this->leaveEntered(null);
        $this->openSends[count($this->openSends) - 1]['after'][] = ['position' => $position, 'phase' => self::POST_SEND, 'failure' => null];
    }

    public function completedAfter(int $position, ?Throwable $failure): void
    {
        $this->leaveEntered($failure);
        $current = count($this->openSends) - 1;
        $this->openSends[$current]['failedAfter'][$position] = $failure !== null;
        $this->openSends[$current]['after'][] = ['position' => $position, 'phase' => self::AFTER_SEND_COMPLETION, 'failure' => $failure === null ? null : ExecutionRecordingTracer::shortClassOf($failure::class)];
        if ($failure !== null) {
            $this->openSends[$current]['failed'] ??= ExecutionRecordingTracer::shortClassOf($failure::class);
        }
    }

    public function completed(?Throwable $failure): void
    {
        $this->leaveEntered($failure);
        $send = array_pop($this->openSends);
        if ($send === null) {
            return;
        }
        if ($send['step'] !== null) {
            foreach ($send['after'] as $after) {
                if ($this->shows($after['position'], $after['phase'])) {
                    [$label, $hints] = $this->afterPhaseOf($after, $send['failed']);
                    $this->tracer->recordStep($label, hints: $hints);
                }
            }
        }
        if ($send['step'] === null) {
            return;
        }
        if ($failure === null) {
            $this->tracer->endStep($send['step'], '', $send['failed'] === null ? [] : [ExecutionHint::critical(ExecutionHint::RESULT, 'failed with ' . $send['failed'] . ', ' . $this->recoveryOf($send['failedAfter']))]);

            return;
        }
        $this->tracer->failStep($send['step'], $failure);
    }

    public function received(Message $message): Message
    {
        $headers = $message->getHeaders();
        $applied = array_filter([
            $headers->containsKey(MessageHeaders::DELIVERY_DELAY) ? 'delay ' . $headers->get(MessageHeaders::DELIVERY_DELAY) . ' ms' : null,
            $headers->containsKey(MessageHeaders::PRIORITY) ? 'priority ' . $headers->get(MessageHeaders::PRIORITY) : null,
            $headers->containsKey(MessageHeaders::TIME_TO_LIVE) ? 'time to live ' . $headers->get(MessageHeaders::TIME_TO_LIVE) . ' ms' : null,
        ]);
        $notes = [];
        $depth = 0;
        foreach (array_keys($this->interceptors) as $position) {
            if ($this->shows($position, self::PRE_RECEIVE)) {
                $notes[] = ['depth' => $depth++, 'name' => 'before receive ' . $this->methodOf($position, self::PRE_RECEIVE)];
            }
        }
        $notes[] = ['depth' => $depth, 'name' => 'take from channel ' . $this->channelName, 'hints' => $applied === [] ? [] : [ExecutionHint::detail(ExecutionHint::RECEIVED_WITH, implode(', ', $applied))]];
        foreach ([self::AFTER_RECEIVE_COMPLETION => 'after receive ', self::POST_RECEIVE => 'after take '] as $phase => $kind) {
            foreach (array_keys($this->interceptors) as $position) {
                if ($this->shows($position, $phase)) {
                    $notes[] = ['depth' => 0, 'name' => $kind . $this->methodOf($position, $phase), 'hints' => $phase === self::AFTER_RECEIVE_COMPLETION ? [ExecutionHint::detail(ExecutionHint::RESULT, 'a message, no failure')] : []];
                }
            }
        }
        $this->tracer->awaitReceive('receive ' . $this->channelName, $notes, [SpanAttribute::EXECUTION_RECEIVED_MESSAGE => self::identityOf($message)]);
        if (! $headers->containsKey(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION)) {
            return $message;
        }
        $location = (string) $headers->get(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION);
        $callback = $headers->containsKey($location) ? $headers->get($location) : null;

        return $callback instanceof AcknowledgementCallback
            ? $this->messageBuilder->fromMessage($message)->setHeader($location, new RecordedAcknowledgementCallback($callback, $this->tracer))->build()
            : $message;
    }

    private function enter(int $position): void
    {
        $current = count($this->openSends) - 1;
        if ($this->openSends[$current]['step'] === null || ! $this->shows($position, self::PRE_SEND)) {
            return;
        }
        $step = $this->tracer->startStep('before send ' . $this->methodOf($position, self::PRE_SEND));
        if ($step !== null) {
            $this->openSends[$current]['entered'][] = ['step' => $step, 'position' => $position, 'hints' => []];
        }
    }

    private function leaveEntered(?Throwable $failure): void
    {
        $current = count($this->openSends) - 1;
        if ($current < 0) {
            return;
        }
        $send = $this->openSends[$current];
        if ($send['left']) {
            return;
        }
        $this->openSends[$current]['left'] = true;
        if ($send['put'] !== null) {
            $failure === null ? $this->tracer->endStep($send['put'], '') : $this->tracer->failStep($send['put'], $failure);
        } elseif ($failure === null && $send['ran'] < count($this->interceptors)) {
            $stop = $this->stopOf($send);
            $deepest = count($send['entered']) - 1;
            if ($deepest >= 0) {
                $send['entered'][$deepest]['hints'][] = $stop;
            }
        }
        foreach (array_reverse($send['entered']) as $position => $entered) {
            $position === 0 && $failure !== null && $send['put'] === null ? $this->tracer->failStep($entered['step'], $failure) : $this->tracer->endStep($entered['step'], '', $entered['hints']);
        }
    }

    /**
     * @param array{messageId: string, ran: int} $send
     */
    private function stopOf(array $send): ExecutionHint
    {
        $stoppedBy = ExecutionRecordingTracer::shortClassOf($this->interceptors[$send['ran']]);
        if ($stoppedBy === self::COLLECTOR) {
            $this->tracer->collect($this->channelName, $send['messageId']);

            return ExecutionHint::critical(ExecutionHint::COLLECTED, 'held until the handler ends');
        }

        return ExecutionHint::critical(ExecutionHint::STOPPED, 'no message passed on');
    }

    /**
     * @param array{position: int, phase: string, failure: ?string} $after
     * @return array{0: string, 1: list<ExecutionHint>}
     */
    private function afterPhaseOf(array $after, ?string $sendFailure): array
    {
        if ($after['phase'] === self::POST_SEND) {
            return ['after successful send ' . $this->methodOf($after['position'], self::POST_SEND), []];
        }
        if ($sendFailure !== null && $this->interceptors[$after['position']] === SendRetryChannelInterceptor::class) {
            return ['recover ' . ExecutionRecordingTracer::shortClassOf(SendRetryChannelInterceptor::class) . '::recover', [ExecutionHint::critical(ExecutionHint::RESULT, 'send failed with ' . $sendFailure)]];
        }

        return ['after send ' . $this->methodOf($after['position'], self::AFTER_SEND_COMPLETION), [$after['failure'] === null ? ExecutionHint::detail(ExecutionHint::RESULT, 'no failure') : ExecutionHint::critical(ExecutionHint::RESULT, 'failed with ' . $after['failure'])]];
    }

    private function shows(int $position, string $phase): bool
    {
        $interceptor = $this->interceptors[$position];

        return ! str_starts_with($interceptor, self::FLOW_TESTING_NAMESPACE)
            && $interceptor !== BeforeSendChannelInterceptor::class
            && in_array($phase, $this->declaredPhases[$position] ?? self::PHASES, true);
    }

    private function methodOf(int $position, string $phase): string
    {
        return ExecutionRecordingTracer::shortClassOf($this->interceptors[$position]) . '::' . $phase;
    }

    /**
     * @return list<ExecutionHint>
     */
    private static function changeOf(Message $before, Message $after): array
    {
        if ($before === $after) {
            return [];
        }
        $hints = [];
        $beforeHeaders = $before->getHeaders()->headers();
        $afterHeaders = $after->getHeaders()->headers();
        $contentTypeChanged = ($beforeHeaders[MessageHeaders::CONTENT_TYPE] ?? null) !== ($afterHeaders[MessageHeaders::CONTENT_TYPE] ?? null) && isset($afterHeaders[MessageHeaders::CONTENT_TYPE]);
        if ($before->getPayload() !== $after->getPayload() || $contentTypeChanged) {
            $hints[] = ExecutionHint::detail(ExecutionHint::PAYLOAD, ExecutionRecorder::typeOf($after->getPayload()) . ($contentTypeChanged ? ', ' . $afterHeaders[MessageHeaders::CONTENT_TYPE] : ''));
        }
        unset($beforeHeaders[MessageHeaders::CONTENT_TYPE], $afterHeaders[MessageHeaders::CONTENT_TYPE]);
        foreach ([
            ExecutionHint::HEADERS_ADDED => array_keys(array_diff_key($afterHeaders, $beforeHeaders)),
            ExecutionHint::HEADERS_REMOVED => array_keys(array_diff_key($beforeHeaders, $afterHeaders)),
            ExecutionHint::HEADERS_CHANGED => array_keys(array_filter(array_intersect_key($afterHeaders, $beforeHeaders), fn (mixed $value, string $name): bool => $value !== $beforeHeaders[$name], ARRAY_FILTER_USE_BOTH)),
        ] as $key => $names) {
            if ($names !== []) {
                $hints[] = ExecutionHint::detail($key, implode(', ', $names));
            }
        }

        return $hints;
    }

    private static function identityOf(Message $message): string
    {
        $headers = $message->getHeaders();

        return $headers->getMessageId() . ($headers->containsKey(MessageHeaders::ROUTING_SLIP) ? ' to ' . $headers->get(MessageHeaders::ROUTING_SLIP) : '');
    }

    private static function retryOf(Message $message): ?ExecutionHint
    {
        $headers = $message->getHeaders();
        if (! $headers->containsKey(DelayedRetryErrorHandler::ECOTONE_RETRY_HEADER)) {
            return null;
        }

        return ExecutionHint::critical(ExecutionHint::RETRY, $headers->get(DelayedRetryErrorHandler::ECOTONE_RETRY_HEADER) . ($headers->containsKey(MessageHeaders::DELIVERY_DELAY) ? ', delayed ' . $headers->get(MessageHeaders::DELIVERY_DELAY) . ' ms' : ''));
    }

    /**
     * @param array<int, bool> $failedAfter
     */
    private function recoveryOf(array $failedAfter): string
    {
        foreach ($failedAfter as $position => $failed) {
            if (! $failed && ($failedAfter[$position - 1] ?? false)) {
                return 'recovered by ' . ExecutionRecordingTracer::shortClassOf($this->interceptors[$position]);
            }
        }

        return 'handled by its channel interceptors without failing the flow';
    }
}
