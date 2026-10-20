<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\PulseSettings;
use Ecotone\Api\Attribute\InternalHandler;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SnapshotReceiver
{
    public const ROUTING_KEY = 'ecotone.pulse.snapshot_received';

    public function __construct(private SnapshotStore $store, #[Autowire(service: 'pulse.clock')] private ClockInterface $clock, private PulseSettings $settings)
    {
    }

    #[InternalHandler(self::ROUTING_KEY)]
    public function receive(string $snapshot): void
    {
        $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        $service = $decoded['serviceName'] ?? null;
        if (! is_string($service) || ! isset($this->settings->serviceChannels[$service])) {
            return;
        }

        $this->store->storeSnapshot($service, $decoded, \DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
