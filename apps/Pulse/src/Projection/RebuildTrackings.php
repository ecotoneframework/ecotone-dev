<?php

declare(strict_types=1);

namespace App\Projection;

use App\Settings\PulseSettings;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use App\Trace\TraceAnswerState;
use App\Trace\TraceQueries;
use App\Trace\TraceQuery;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RebuildTrackings
{
    public function __construct(
        private TraceQueries $queries,
        private PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
    ) {
    }

    public function of(ServiceSnapshot $service): RebuildReport
    {
        if ($service->status !== ServiceStatus::Connected) {
            return RebuildReport::unavailable();
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $query = TraceQuery::projectionRebuilds($now->modify('-' . $this->settings->trackingDays . ' days'), $this->settings->trackingDays);
        [$answer] = $this->queries->askAll([$service->service], $query);
        $tracked = is_array($answer->result['trackings'] ?? null) ? $answer->result['trackings'] : [];
        $rebuilds = array_values(array_filter(array_map(static fn (mixed $tracking): ?Rebuild => is_array($tracking) ? Rebuild::fromTracking($tracking) : null, $tracked)));

        return new RebuildReport($rebuilds, $answer->state === TraceAnswerState::Waiting, $answer->state === TraceAnswerState::NotAnswering, ($answer->result['truncated'] ?? false) === true, is_int($answer->result['cap'] ?? null) ? $answer->result['cap'] : null);
    }
}
