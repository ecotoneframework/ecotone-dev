<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ErrorChannel;

use Ecotone\Messaging\Attribute\Poller;
use Ecotone\Messaging\Attribute\Scheduled;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class FailingScheduledExample
{
    public const ENDPOINT_ID = 'failing.scheduler';
    public const REQUEST_CHANNEL = 'failing.scheduler.input';

    #[Scheduled(self::REQUEST_CHANNEL, self::ENDPOINT_ID)]
    #[Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
    public function poll(): string
    {
        return 'payload';
    }

    #[\Ecotone\Messaging\Attribute\ServiceActivator(self::REQUEST_CHANNEL)]
    public function handle(string $payload): void
    {
        throw new InvalidArgumentException('boom');
    }
}
