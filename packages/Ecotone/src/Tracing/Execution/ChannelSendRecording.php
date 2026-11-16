<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;
use function count;

use Throwable;

/**
 * licence Apache-2.0
 */
final class ChannelSendRecording
{
    public const COLLECTOR = 'MessageCollectorChannelInterceptor';

    /** @var list<array{step: ?ExecutionStep, ran: int}> */
    private array $openSends = [];

    /**
     * @param list<string> $interceptors class of each channel interceptor, in the order they run
     */
    public function __construct(
        private string $channelName,
        private array $interceptors,
        private ExecutionRecordingTracer $tracer,
    ) {
    }

    public function started(): void
    {
        $this->openSends[] = ['step' => $this->tracer->startStep('send ' . $this->channelName), 'ran' => 0];
    }

    public function interceptorRan(int $position): void
    {
        $this->openSends[count($this->openSends) - 1]['ran'] = $position + 1;
        $this->tracer->recordStep('channel interceptor ' . ExecutionRecordingTracer::shortClassOf($this->interceptors[$position]));
    }

    public function completed(?Throwable $failure): void
    {
        $send = array_pop($this->openSends);
        if ($send === null) {
            return;
        }
        if ($failure === null && $send['ran'] < count($this->interceptors)) {
            $stoppedBy = ExecutionRecordingTracer::shortClassOf($this->interceptors[$send['ran']]);
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
}
