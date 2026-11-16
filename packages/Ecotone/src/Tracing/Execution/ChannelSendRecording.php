<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;
use function count;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MethodInterceptor\BeforeSendChannelInterceptor;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ChannelSendRecording
{
    public const COLLECTOR = 'MessageCollectorChannelInterceptor';
    private const FLOW_TESTING_NAMESPACE = 'Ecotone\\Lite\\Test\\';

    /** @var list<array{step: ?ExecutionStep, ran: int, messageId: string, failed: ?string, failedAfter: array<int, bool>}> */
    private array $openSends = [];
    /** @var array<string, true> message id => held by the collector until the handler ends */
    private array $collected = [];

    /**
     * @param list<string> $interceptors class of each channel interceptor, in the order they run
     */
    public function __construct(
        private string $channelName,
        private array $interceptors,
        private bool $onlyFlowTestingIntercepts,
        private ExecutionRecordingTracer $tracer,
    ) {
    }

    public function started(Message $message): void
    {
        $messageId = $message->getHeaders()->getMessageId();
        $released = isset($this->collected[$messageId]);
        unset($this->collected[$messageId]);
        $this->openSends[] = [
            'step' => $this->onlyFlowTestingIntercepts ? null : $this->tracer->startEntry(($released ? 'release collected message to ' : 'send ') . $this->channelName),
            'ran' => 0,
            'messageId' => $messageId,
            'failed' => null,
            'failedAfter' => [],
        ];
    }

    public function interceptorRan(int $position): void
    {
        $this->openSends[count($this->openSends) - 1]['ran'] = $position + 1;
        if (! str_starts_with($this->interceptors[$position], self::FLOW_TESTING_NAMESPACE) && $this->interceptors[$position] !== BeforeSendChannelInterceptor::class) {
            $this->tracer->recordStep('channel interceptor ' . ExecutionRecordingTracer::shortClassOf($this->interceptors[$position]));
        }
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
        if ($failure === null && $send['failed'] !== null) {
            $this->tracer->recordStep('send failed with ' . $send['failed'] . ', ' . $this->recoveryOf($send['failedAfter']));
        }
        if ($failure === null && $send['failed'] === null && $send['ran'] < count($this->interceptors)) {
            $stoppedBy = ExecutionRecordingTracer::shortClassOf($this->interceptors[$send['ran']]);
            if ($stoppedBy === self::COLLECTOR) {
                $this->collected[$send['messageId']] = true;
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
