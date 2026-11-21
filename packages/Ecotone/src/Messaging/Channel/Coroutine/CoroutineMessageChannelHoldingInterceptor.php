<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Config\ConfigurationException;
use Swoole\Coroutine;

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
final class CoroutineMessageChannelHoldingInterceptor extends AbstractChannelInterceptor
{
    public function __construct(private CoroutineMessageChannelRelease $release, private string $channelName)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        if (Coroutine::getCid() < 1 && ! $message->getHeaders()->containsKey(ErrorContext::DLQ_MESSAGE_REPLIED)) {
            throw ConfigurationException::create("Coroutine message channel `{$this->channelName}` cannot send outside a Swoole or OpenSwoole coroutine. This can happen in a console command, a consumer started without --coroutines, or an HTTP server with enable_coroutine disabled. For HTTP requests set enable_coroutine=true on the server; for a sending consumer run ecotone:run <source-channel> --coroutines=N; for a console command run the command inside a Swoole or OpenSwoole coroutine. In tests, exclude the production registration with #[Environment(['prod'])] and register SimpleMessageChannelBuilder::createQueueChannel('{$this->channelName}') in the test environment, then call the flow test support's run('{$this->channelName}'). An uncaught send failure rolls back the sender's Ecotone transaction.");
        }
        return $this->release->hold($this->channelName, $message) ? null : $message;
    }
}
