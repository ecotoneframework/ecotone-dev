<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsyncMisplaced;

use Ecotone\Messaging\Attribute\DelayedRetry;
use Ecotone\Messaging\Attribute\Poller;
use Ecotone\Messaging\Attribute\Scheduled;

/**
 * licence Enterprise
 *
 * Wrong placement: #[DelayedRetry] on an Inbound Channel Adapter (#[Scheduled]).
 * The framework must reject this — there is no source Message Channel to reschedule a delayed retry into.
 */
final class InboundChannelAdapterWithDelayedRetry
{
    #[DelayedRetry(initialDelayMs: 1, maxAttempts: 2)]
    #[Scheduled('inboundDelayedRetryChannel', 'inboundDelayedRetry')]
    #[Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
    public function emit(): string
    {
        return 'payload';
    }
}
