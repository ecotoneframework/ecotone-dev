<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Messaging\Attribute\DelayedRetry;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\ErrorChannelService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Throwable;

/**
 * licence Apache-2.0
 */
class PollingConsumerErrorChannelInterceptor
{
    public function __construct(
        private ErrorChannelService $errorChannelService,
        private ChannelResolver $channelResolver,
        private AsyncEndpointAnnotationContext $asyncEndpointAnnotationContext,
    ) {
    }

    public function handle(MethodInvocation $methodInvocation, Message $requestMessage)
    {
        try {
            return $methodInvocation->proceed();
        } catch (Throwable $exception) {
            if (! $this->tryToSendToErrorChannel($exception, $requestMessage)) {
                throw $exception;
            }
        }
    }

    private function tryToSendToErrorChannel(Throwable $exception, Message $requestMessage): bool
    {
        if ($requestMessage->getHeaders()->containsKey(MessageHeaders::CONSUMER_POLLING_METADATA)) {
            /** @var PollingMetadata $pollingMetadata */
            $pollingMetadata = $requestMessage->getHeaders()->get(MessageHeaders::CONSUMER_POLLING_METADATA);

            if ($pollingMetadata->isStoppedOnError()) {
                return false;
            }

            $errorChannelName = $this->resolveHandlerScopedErrorChannelName()
                ?? $pollingMetadata->getErrorChannelName();

            if (! $errorChannelName) {
                return false;
            }

            $polledChannelName = $requestMessage->getHeaders()->containsKey(MessageHeaders::POLLED_CHANNEL_NAME)
                ? $requestMessage->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME)
                : null;

            $this->errorChannelService->handle(
                $requestMessage,
                $exception,
                $this->channelResolver->resolve($errorChannelName),
                $polledChannelName,
                $polledChannelName === null ? $pollingMetadata->getEndpointId() : null,
            );

            return true;
        }

        return false;
    }

    private function resolveHandlerScopedErrorChannelName(): ?string
    {
        $handlerEndpointId = null;
        $hasDelayedRetry = false;

        foreach ($this->asyncEndpointAnnotationContext->getCurrentAnnotations() as $annotation) {
            if ($annotation instanceof ErrorChannel) {
                return $annotation->errorChannelName;
            }
            if ($annotation instanceof DelayedRetry) {
                $hasDelayedRetry = true;
            } elseif ($annotation instanceof AsynchronousRunningEndpoint) {
                $handlerEndpointId = $annotation->getEndpointId();
            }
        }

        if ($hasDelayedRetry && $handlerEndpointId !== null) {
            return DelayedRetry::generateChannelName($handlerEndpointId);
        }

        return null;
    }
}
