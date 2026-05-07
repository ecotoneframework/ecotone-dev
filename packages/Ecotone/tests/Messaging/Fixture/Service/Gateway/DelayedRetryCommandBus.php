<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Service\Gateway;

use Ecotone\Messaging\Attribute\DelayedRetry;
use Ecotone\Modelling\CommandBus;

/**
 * Custom Command Bus declaring a per-gateway #[DelayedRetry] policy.
 *
 * Failures from any command sent through this bus are routed to a generated
 * Error Channel; the framework retries with the configured backoff and routes
 * to the dead letter channel on exhaustion.
 */
#[DelayedRetry(
    initialDelayMs: 1,
    multiplier: 1,
    maxAttempts: 1,
    deadLetterChannel: 'gatewayRetryDeadLetter',
)]
interface DelayedRetryCommandBus extends CommandBus
{
}
