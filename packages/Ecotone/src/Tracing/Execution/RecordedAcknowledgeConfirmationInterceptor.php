<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Endpoint\AcknowledgeConfirmationInterceptor;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class RecordedAcknowledgeConfirmationInterceptor extends AcknowledgeConfirmationInterceptor
{
    public function __construct(RetryRunner $retryRunner, LoggingGateway $logger, private MessageBuilder $messageBuilder, private ExecutionRecordingTracer $tracer)
    {
        parent::__construct($retryRunner, $logger);
    }

    public function ack(MethodInvocation $methodInvocation, Message $message)
    {
        $callback = self::acknowledgementOf($message);
        $recorded = $callback === null ? null : new RecordedAcknowledgementCallback($callback, $this->tracer);
        $result = parent::ack($methodInvocation, $recorded === null ? $message : $this->messageBuilder->fromMessage($message)->setHeader((string) $message->getHeaders()->get(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION), $recorded)->build());
        if ($callback?->isAutoAcked() === false && ! $recorded->acknowledged) {
            $this->tracer->recordStep('acknowledge', hints: [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'left to the handler')]);
        }

        return $result;
    }

    private static function acknowledgementOf(Message $message): ?AcknowledgementCallback
    {
        $headers = $message->getHeaders();
        if (! $headers->containsKey(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION)) {
            return null;
        }
        $location = (string) $headers->get(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION);
        $callback = $headers->containsKey($location) ? $headers->get($location) : null;

        return $callback instanceof AcknowledgementCallback ? $callback : null;
    }
}
