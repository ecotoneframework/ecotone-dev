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
    /** @var list<array{reply: ?string, routed: bool}> */
    private array $openRequests = [];

    public function __construct(private ExecutionRecordingTracer $tracer)
    {
    }

    public function started(): void
    {
        $this->openRequests[] = ['reply' => null, 'routed' => false];
    }

    public function replied(?Message $reply): void
    {
        if ($reply === null) {
            $this->tracer->recordStep('no reply');

            return;
        }
        $this->openRequests[count($this->openRequests) - 1]['reply'] = 'reply ' . ExecutionRecorder::typeOf($reply->getPayload());
    }

    public function routedTo(string $destination, Closure $send): void
    {
        $position = count($this->openRequests) - 1;
        $this->openRequests[$position]['routed'] = true;
        $step = $this->tracer->startStep(($this->openRequests[$position]['reply'] ?? 'reply') . ' to ' . $destination);
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
        $this->openRequests[count($this->openRequests) - 1]['routed'] = true;
    }

    public function finished(Message $request, bool $failed): void
    {
        $open = array_pop($this->openRequests);
        if ($failed || $open === null || $open['reply'] === null || $open['routed']) {
            return;
        }
        $this->tracer->recordStep($open['reply'] . ($request->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL) ? ' to the reply channel of the request' : ' dropped, as no channel waits for it'));
    }
}
