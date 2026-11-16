<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;
use function count;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
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
    private const FLOW_TESTING_NAMESPACE = 'Ecotone\\Lite\\Test\\';

    /** @var list<array{step: ?ExecutionStep, ran: int, messageId: string, failed: ?string, failedAfter: array<int, bool>, postSentAfter: array<int, true>}> */
    private array $openSends = [];

    /** @var list<string> class of each channel interceptor, in the order they run */
    private array $interceptors;

    /**
     * @param list<string|object> $interceptors class of each channel interceptor, or the interceptor when its class is known only to the container
     */
    public function __construct(
        private string $channelName,
        array $interceptors,
        private bool $onlyFlowTestingIntercepts,
        private MessageBuilder $messageBuilder,
        private ExecutionRecordingTracer $tracer,
    ) {
        $this->interceptors = array_map(fn (string|object $interceptor): string => is_object($interceptor) ? $interceptor::class : $interceptor, $interceptors);
    }

    public function started(Message $message): void
    {
        $messageId = $message->getHeaders()->getMessageId();
        $released = $this->tracer->releaseCollected($this->channelName, $messageId);
        $this->openSends[] = [
            'step' => $this->onlyFlowTestingIntercepts ? null : $this->tracer->startEntry(($released ? 'release collected message to ' : 'send ') . $this->channelName . self::retryOf($message)),
            'ran' => 0,
            'messageId' => $messageId,
            'failed' => null,
            'failedAfter' => [],
            'postSentAfter' => [],
        ];
    }

    public function interceptorRan(int $position): void
    {
        $this->openSends[count($this->openSends) - 1]['ran'] = $position + 1;
        if (! str_starts_with($this->interceptors[$position], self::FLOW_TESTING_NAMESPACE) && $this->interceptors[$position] !== BeforeSendChannelInterceptor::class) {
            $this->tracer->recordStep('channel interceptor ' . ExecutionRecordingTracer::shortClassOf($this->interceptors[$position]));
        }
    }

    private static function retryOf(Message $message): string
    {
        $headers = $message->getHeaders();
        if (! $headers->containsKey(DelayedRetryErrorHandler::ECOTONE_RETRY_HEADER)) {
            return '';
        }

        return ' as retry ' . $headers->get(DelayedRetryErrorHandler::ECOTONE_RETRY_HEADER) . ($headers->containsKey(MessageHeaders::DELIVERY_DELAY) ? ', delayed ' . $headers->get(MessageHeaders::DELIVERY_DELAY) . ' ms' : '');
    }

    public function postSentAfter(int $position): void
    {
        $this->openSends[count($this->openSends) - 1]['postSentAfter'][$position] = true;
    }

    public function received(Message $message): Message
    {
        $headers = $message->getHeaders();
        $applied = array_filter([
            $headers->containsKey(MessageHeaders::DELIVERY_DELAY) ? 'delay ' . $headers->get(MessageHeaders::DELIVERY_DELAY) . ' ms' : null,
            $headers->containsKey(MessageHeaders::PRIORITY) ? 'priority ' . $headers->get(MessageHeaders::PRIORITY) : null,
            $headers->containsKey(MessageHeaders::TIME_TO_LIVE) ? 'time to live ' . $headers->get(MessageHeaders::TIME_TO_LIVE) . ' ms' : null,
        ]);
        $through = $this->namesOf(array_keys($this->interceptors));
        $this->tracer->awaitReceive('receive ' . $this->channelName, array_values(array_filter([
            $applied === [] ? null : 'received with ' . implode(', ', $applied),
            $through === '' ? null : 'pre-receive, after-receive-completion and post-receive through ' . $through,
        ])));
        if (! $headers->containsKey(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION)) {
            return $message;
        }
        $location = (string) $headers->get(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION);
        $callback = $headers->containsKey($location) ? $headers->get($location) : null;

        return $callback instanceof AcknowledgementCallback
            ? $this->messageBuilder->fromMessage($message)->setHeader($location, new RecordedAcknowledgementCallback($callback, $this->tracer))->build()
            : $message;
    }

    /**
     * @param list<int> $positions
     */
    private function namesOf(array $positions): string
    {
        $names = [];
        foreach ($positions as $position) {
            $interceptor = $this->interceptors[$position];
            if (! str_starts_with($interceptor, self::FLOW_TESTING_NAMESPACE) && $interceptor !== BeforeSendChannelInterceptor::class) {
                $names[] = ExecutionRecordingTracer::shortClassOf($interceptor);
            }
        }

        return implode(', ', $names);
    }

    public function completedAfter(int $position, ?Throwable $failure): void
    {
        $current = count($this->openSends) - 1;
        $this->openSends[$current]['failedAfter'][$position] = $failure !== null;
        if ($failure !== null) {
            $this->openSends[$current]['failed'] ??= ExecutionRecordingTracer::shortClassOf($failure::class);
        }
    }

    public function completed(?Throwable $failure): void
    {
        $send = array_pop($this->openSends);
        if ($send === null) {
            return;
        }
        foreach (['post-send' => array_keys($send['postSentAfter']), 'after-send-completion' => array_keys($send['failedAfter'])] as $phase => $positions) {
            $through = $this->namesOf($positions);
            if ($through !== '') {
                $this->tracer->recordStep($phase . ' through ' . $through);
            }
        }
        if ($failure === null && $send['failed'] !== null) {
            $this->tracer->recordStep('send failed with ' . $send['failed'] . ', ' . $this->recoveryOf($send['failedAfter']));
        }
        if ($failure === null && $send['failed'] === null && $send['ran'] < count($this->interceptors)) {
            $stoppedBy = ExecutionRecordingTracer::shortClassOf($this->interceptors[$send['ran']]);
            if ($stoppedBy === self::COLLECTOR) {
                $this->tracer->collect($this->channelName, $send['messageId']);
            }
            $this->tracer->recordStep($stoppedBy === self::COLLECTOR ? 'collected, held until the handler ends' : 'channel interceptor ' . $stoppedBy . ' stopped the message');
        }
        if ($send['step'] === null) {
            return;
        }
        if ($failure === null) {
            $this->tracer->endStep($send['step'], '');

            return;
        }
        $this->tracer->failStep($send['step'], $failure);
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
