<?php

declare(strict_types=1);

namespace App\Graph;

use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;

final readonly class ServiceFlows
{
    /**
     * @param list<ServiceFlow> $flows
     * @param list<array{kind: string, message: string}> $notices
     * @param list<string> $notAnswering
     */
    private function __construct(public array $flows, public array $notices, public array $notAnswering)
    {
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     */
    public static function of(array $snapshots): self
    {
        $flows = [];
        $notices = [];
        $notAnswering = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->status !== ServiceStatus::Connected) {
                $notAnswering[] = $snapshot->service;
            }
            if (! $snapshot->hasSnapshot()) {
                continue;
            }
            $flow = ServiceFlow::fromSnapshot($snapshot);
            if ($flow === null) {
                $notices[] = ['kind' => 'unreadable', 'message' => "{$snapshot->service}: snapshot could not be read, so the service is left out. Update the service to a version that answers Pulse snapshot requests, or check its flow graph with ConfiguredMessagingSystem::getMessageFlowGraph()."];
                continue;
            }
            $flows[] = $flow;
            if ($flow->isStale()) {
                $notices[] = ['kind' => 'stale', 'message' => "{$snapshot->service}: stale, showing the last snapshot from {$snapshot->ageSeconds}s ago because the service is {$snapshot->status->value}."];
            }
        }

        return new self($flows, $notices, $notAnswering);
    }

    public function isIncomplete(): bool
    {
        return $this->notAnswering !== [];
    }
}
