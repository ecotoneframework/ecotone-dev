<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\Gathering\PartCompletion;

/**
 * licence Apache-2.0
 */
final class RecordedPartCompletion implements PartCompletion
{
    public function __construct(private PartCompletion $partCompletion, private ReplyRecording $recording)
    {
    }

    public function validateRoute(Message $request, array $destinations): void
    {
        $this->partCompletion->validateRoute($request, $destinations);
    }

    public function complete(Message $request, ?Message $reply): bool
    {
        $completed = $this->partCompletion->complete($request, $reply);
        if ($completed) {
            $this->recording->completedAsPart();
        }

        return $completed;
    }
}
