<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;
use function count;

use Ecotone\Api\Messaging\Message;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ChannelSendRecording
{
    public const COLLECTOR = 'MessageCollectorChannelInterceptor';

    /** @var list<array{step: ?ExecutionStep, ran: int, messageId: string}> */
    private array $openSends = [];
    /** @var array<string, true> message id => held by the collector until the handler ends */
    private array $collected = [];

    /**
     * @param list<string> $interceptors class of each channel interceptor, in the order they run
     */
    public function __construct(
        private string $channelName,
        private array $interceptors,
        private ExecutionRecordingTracer $tracer,
    ) {
    }

    public function started(Message $message): void
    {
        $messageId = $message->getHeaders()->getMessageId();
        $released = isset($this->collected[$messageId]);
        unset($this->collected[$messageId]);
        $this->openSends[] = [
            'step' => $this->tracer->startStep(($released ? 'release collected message to ' : 'send ') . $this->channelName),
            'ran' => 0,
            'messageId' => $messageId,
        ];
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
}
