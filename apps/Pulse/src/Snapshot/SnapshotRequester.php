<?php

declare(strict_types=1);

namespace App\Snapshot;

use DateTimeImmutable;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class SnapshotRequester
{
    public const ROUTING_KEY = 'ecotone.pulse.snapshot';

    public function __construct(private DistributedBus $distributedBus, private SnapshotStore $store, #[Autowire(service: 'pulse.clock')] private ClockInterface $clock)
    {
    }

    public function request(string $service): void
    {
        $requestedAt = DateTimeImmutable::createFromInterface($this->clock->now());

        try {
            $this->distributedBus->sendMessage($service, self::ROUTING_KEY, '{}', MediaType::APPLICATION_JSON, replyRoutingKey: SnapshotReceiver::ROUTING_KEY);
        } catch (Throwable $exception) {
            $this->store->storeRequest($service, $requestedAt, $exception->getMessage());

            return;
        }

        $this->store->storeRequest($service, $requestedAt);
    }
}
