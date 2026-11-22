<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Psr\Container\ContainerInterface;

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
final class CoroutineMessageChannelReplayInterceptor extends AbstractChannelInterceptor
{
    public function __construct(private string $channelName, private ContainerInterface $container)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        $headers = $message->getHeaders();
        if (! $headers->containsKey(ErrorContext::DLQ_MESSAGE_REPLIED) || ! $headers->containsKey(MessagingEntrypointService::ENTRYPOINT) || $headers->get(MessagingEntrypointService::ENTRYPOINT) !== $this->channelName) {
            return $message;
        }
        $this->container->get(CoroutineMessageChannelReplay::SERVICE_ID_PREFIX . $this->channelName)->replay($message);

        return null;
    }
}
