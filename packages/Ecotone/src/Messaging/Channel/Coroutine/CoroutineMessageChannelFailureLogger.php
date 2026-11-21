<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Attribute\EndpointId;
use Ecotone\Messaging\Execution\ExecutionScope;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class CoroutineMessageChannelFailureLogger
{
    public function __construct(private array $channelNames, private LoggingGateway $logger)
    {
    }

    public function recordFailedEndpoint(MethodInvocation $invocation, EndpointId $endpoint): mixed
    {
        try {
            return $invocation->proceed();
        } catch (Throwable $exception) {
            $this->failure()->endpointId = $endpoint->endpointId;
            throw $exception;
        }
    }

    public function log(MethodInvocation $invocation, Message $message): mixed
    {
        $failure = $this->failure();
        $failure->endpointId = '';
        try {
            return $invocation->proceed();
        } catch (Throwable $exception) {
            $channelName = $message->getHeaders()->containsKey(MessageHeaders::POLLED_CHANNEL_NAME) ? $message->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME) : '';
            if (in_array(needle: $channelName, haystack: $this->channelNames, strict: true)) {
                $endpointId = $failure->endpointId !== '' ? $failure->endpointId : $channelName;
                $this->logger->error("Coroutine message channel `{$channelName}` dropped a message after endpoint `{$endpointId}` failed. Configure #[Asynchronous('{$channelName}', asynchronousExecution: [new ErrorChannel('failures')])] or PollingMetadata::create('{$channelName}')->withErrorChannelName('failures') to keep failed messages in a durable queue or dead letter.", ['headers' => $message->getHeaders()->headers(), 'exception' => $exception, 'channel' => $channelName, 'endpoint_id' => $endpointId]);
            }
            throw $exception;
        } finally {
            $failure->endpointId = '';
        }
    }

    private function failure(): CoroutineMessageChannelFailure
    {
        return ExecutionScope::stateOf($this, static fn () => new CoroutineMessageChannelFailure());
    }
}
