<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Execution\ExecutionScope;

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
final class CoroutineMessageRelease
{
    public function hold(string $channel, Message $message): bool
    {
        $state = $this->state();
        if (! $state->active) {
            return false;
        }
        $state->messages[] = [$channel, $message];
        return true;
    }

    public function release(MethodInvocation $invocation, #[Reference] ConfiguredMessagingSystem $messagingSystem): mixed
    {
        $state = $this->state();
        if ($state->active) {
            return $invocation->proceed();
        }
        $state->active = true;
        try {
            $result = $invocation->proceed();
            $messages = $state->messages;
        } finally {
            $state->messages = [];
            $state->active = false;
        }
        foreach ($messages as [$channel, $message]) {
            $messagingSystem->getMessageChannelByName($channel)->send($message);
        }
        return $result;
    }

    private function state(): CoroutineMessageReleaseState
    {
        return ExecutionScope::stateOf($this, static fn () => new CoroutineMessageReleaseState());
    }
}
