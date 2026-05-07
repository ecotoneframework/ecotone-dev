<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\InboundChannelAdapter;

use Ecotone\Messaging\Attribute\Poller;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Attribute\ServiceActivator;
use RuntimeException;

/**
 * licence Apache-2.0
 */
final class ReplayableInboundChannelAdapterExample
{
    public const ENDPOINT_ID = 'failingInboundAdapter';
    public const REQUEST_CHANNEL = 'failingInboundAdapterRequestChannel';

    public bool $shouldFail = true;
    public int $invocations = 0;
    /** @var string[] */
    public array $processedPayloads = [];
    private bool $hasEmitted = false;

    #[Scheduled(self::REQUEST_CHANNEL, self::ENDPOINT_ID)]
    #[Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
    public function emit(): ?string
    {
        if ($this->hasEmitted) {
            return null;
        }
        $this->hasEmitted = true;

        return 'first-payload';
    }

    #[ServiceActivator(self::REQUEST_CHANNEL)]
    public function handle(string $payload): void
    {
        $this->invocations++;
        if ($this->shouldFail) {
            throw new RuntimeException('simulated');
        }
        $this->processedPayloads[] = $payload;
    }
}
