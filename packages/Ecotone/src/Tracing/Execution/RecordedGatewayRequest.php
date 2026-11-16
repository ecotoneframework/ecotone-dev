<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Handler\MessageProcessor;

/**
 * licence Apache-2.0
 */
final class RecordedGatewayRequest implements MessageProcessor
{
    public function __construct(private MessageProcessor $gatewayInternalProcessor, private ExecutionRecordingTracer $tracer)
    {
    }

    public function process(Message $message): ?Message
    {
        $headers = $message->getHeaders();
        if ($headers->containsKey(MessageHeaders::ROUTING_SLIP) && (string) $headers->get(MessageHeaders::ROUTING_SLIP) !== '') {
            $this->tracer->hintOpenStep(ExecutionHint::critical(ExecutionHint::ROUTING_SLIP, 'none → ' . str_replace(',', ', ', (string) $headers->get(MessageHeaders::ROUTING_SLIP))));
        }

        return $this->gatewayInternalProcessor->process($message);
    }
}
