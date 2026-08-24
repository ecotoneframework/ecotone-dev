<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Behat\InterceptedScheduled;

use Ecotone\Api\Before;
use Ecotone\Api\Poller;
use Ecotone\Api\Presend;
use Ecotone\Api\Scheduled;
use Ecotone\Api\ServiceActivator;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;

/**
 * licence Apache-2.0
 */
class InterceptedScheduledExample
{
    private int $requestData = 0;

    #[Scheduled('handle', 'scheduled.handler')]
    #[Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
    public function buy(): int
    {
        return 10;
    }

    #[ServiceActivator('handle')]
    public function handle(int $payload, array $metadata, MessagingEntrypointService $messagingEntrypoint): void
    {
        if (isset($metadata['entrypoint'])) {
            $this->requestData = $payload;
        } else {
            $messagingEntrypoint->sendWithHeaders($payload, ['entrypoint' => true], 'handle');
        }
    }

    #[ServiceActivator('getRequestedData')]
    public function getRequestedData(): int
    {
        return $this->requestData;
    }

    #[Presend(pointcut: InterceptedScheduledExample::class . '::' . 'handle')]
    public function beforeSend(int $payload): int
    {
        return $payload * 2;
    }

    #[Before(pointcut: InterceptedScheduledExample::class . '::' . 'handle')]
    public function before(int $payload): int
    {
        return $payload * 2;
    }
}
