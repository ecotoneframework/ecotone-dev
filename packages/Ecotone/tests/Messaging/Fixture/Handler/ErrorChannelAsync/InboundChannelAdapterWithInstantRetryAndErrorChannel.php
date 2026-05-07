<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsync;

use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Messaging\Attribute\Poller;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Modelling\Attribute\InstantRetry;
use RuntimeException;

/**
 * licence Enterprise
 *
 * #[InstantRetry] retries the handler in-process before forwarding to #[ErrorChannel]
 * on an Inbound Channel Adapter (#[Scheduled]).
 */
final class InboundChannelAdapterWithInstantRetryAndErrorChannel
{
    public const ENDPOINT_ID = 'inboundInstantRetry';
    public const REQUEST_CHANNEL = 'inboundInstantRetryChannel';
    public const ERROR_CHANNEL = 'inboundInstantRetryErrorChannel';

    public int $invocations = 0;
    public int $maxFailures = 0;
    public bool $hasEmitted = false;

    #[InstantRetry(retryTimes: 2)]
    #[ErrorChannel(self::ERROR_CHANNEL)]
    #[Scheduled(self::REQUEST_CHANNEL, self::ENDPOINT_ID)]
    #[Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
    public function emit(): ?string
    {
        if ($this->hasEmitted) {
            return null;
        }
        $this->hasEmitted = true;

        return 'payload';
    }

    #[ServiceActivator(self::REQUEST_CHANNEL)]
    public function handle(string $payload): void
    {
        $this->invocations++;
        if ($this->invocations <= $this->maxFailures) {
            throw new RuntimeException('simulated');
        }
    }
}
