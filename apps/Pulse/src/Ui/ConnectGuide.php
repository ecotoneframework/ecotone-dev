<?php

declare(strict_types=1);

namespace App\Ui;

use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;

final readonly class ConnectGuide
{
    public const DEFAULT_FRAMEWORK = 'symfony';
    public const STEPS = 4;

    /**
     * @param list<string> $answering
     * @param list<array{service: string, status: Status, label: string}> $notAnswering
     * @param array{label: string, service: string, anchor: ?string}|null $action
     * @param list<string> $frameworkOrder
     */
    private function __construct(
        public Status $status,
        public string $sentence,
        public ?array $action,
        public array $answering,
        public array $notAnswering,
        public string $framework,
        public array $frameworkOrder,
        public ?string $focus,
        public bool $focusConfigured,
        public ?ServiceSnapshot $focusSnapshot,
    ) {
    }

    /**
     * @param list<ServiceSnapshot> $services
     * @param list<string> $frameworks
     */
    public static function of(?string $focus, array $services, array $frameworks, string $requestedFramework): self
    {
        $answering = [];
        $notAnswering = [];
        $focusSnapshot = null;
        foreach ($services as $service) {
            if ($service->service === $focus) {
                $focusSnapshot = $service;
            }
            if ($service->status === ServiceStatus::Connected) {
                $answering[] = $service->service;
                continue;
            }
            $notAnswering[] = ['service' => $service->service, 'status' => self::statusOf($service), 'label' => self::labelOf($service)];
        }

        $framework = self::frameworkFor($requestedFramework, $focusSnapshot, $frameworks);
        $order = [$framework, ...array_values(array_diff($frameworks, [$framework]))];

        if ($focus !== null) {
            [$status, $sentence, $action] = self::focused($focus, $focusSnapshot);
        } else {
            [$status, $sentence, $action] = self::overall($services, $answering, $notAnswering);
        }

        return new self($status, $sentence, $action, $answering, $notAnswering, $framework, $order, $focus, $focusSnapshot !== null, $focusSnapshot);
    }

    /**
     * @param list<string> $frameworks
     */
    private static function frameworkFor(string $requested, ?ServiceSnapshot $focus, array $frameworks): string
    {
        $reported = $focus?->snapshot['overview']['framework'] ?? null;
        foreach ([$requested, is_string($reported) ? $reported : '', self::DEFAULT_FRAMEWORK] as $candidate) {
            if (in_array($candidate, $frameworks, true)) {
                return $candidate;
            }
        }

        return $frameworks[0] ?? self::DEFAULT_FRAMEWORK;
    }

    /**
     * @return array{0: Status, 1: string, 2: array{label: string, service: string, anchor: ?string}|null}
     */
    private static function focused(string $focus, ?ServiceSnapshot $snapshot): array
    {
        if ($snapshot === null) {
            return [Status::Info, sprintf('%s is not in PULSE_SERVICES yet: the %d steps below connect it.', $focus, self::STEPS), null];
        }

        return match ($snapshot->status) {
            ServiceStatus::Connected => [Status::Ok, sprintf('%s is connected: it answered Pulse %d s ago. Nothing is left to do.', $focus, (int) $snapshot->ageSeconds), ['label' => 'Open ' . $focus, 'service' => $focus, 'anchor' => null]],
            ServiceStatus::Waiting => [Status::Info, $snapshot->hasSnapshot()
                ? sprintf('%s is in PULSE_SERVICES and last answered Pulse %d s ago; its page asks it again.', $focus, (int) $snapshot->ageSeconds)
                : sprintf('%s is in PULSE_SERVICES and has not answered Pulse yet.', $focus), ['label' => 'Open ' . $focus, 'service' => $focus, 'anchor' => null]],
            ServiceStatus::ChannelMissing => [Status::Critical, sprintf('%s is in PULSE_SERVICES, but its request channel %s does not exist yet.', $focus, $snapshot->channel), ['label' => 'Diagnose ' . $focus, 'service' => $focus, 'anchor' => 'diagnose']],
            ServiceStatus::Unreachable => [Status::Critical, sprintf('%s is in PULSE_SERVICES, but it does not answer Pulse.', $focus), ['label' => 'Diagnose ' . $focus, 'service' => $focus, 'anchor' => 'diagnose']],
        };
    }

    /**
     * @param list<ServiceSnapshot> $services
     * @param list<string> $answering
     * @param list<array{service: string, status: Status, label: string}> $notAnswering
     * @return array{0: Status, 1: string, 2: array{label: string, service: string, anchor: ?string}|null}
     */
    private static function overall(array $services, array $answering, array $notAnswering): array
    {
        if ($services === []) {
            return [Status::Info, sprintf('No service is in PULSE_SERVICES yet: the %d steps below connect the first one.', self::STEPS), null];
        }
        if ($notAnswering === []) {
            return [Status::Ok, sprintf('%s %s Pulse. To connect another service, follow the steps below.', self::names($answering), count($answering) === 1 ? 'answers' : 'all answer'), null];
        }

        $status = Status::worstOf(...array_map(static fn (array $service): Status => $service['status'], $notAnswering));
        $failing = array_values(array_filter($notAnswering, static fn (array $service): bool => $service['status'] === Status::Critical));
        $names = array_map(static fn (array $service): string => $service['service'], $notAnswering);
        $sentence = sprintf(
            '%d of %d configured services answer Pulse; %s %s not answered yet.',
            count($answering),
            count($services),
            self::names($names),
            count($names) === 1 ? 'has' : 'have',
        );

        return [$status, $sentence, $failing === [] ? null : ['label' => 'Diagnose ' . $failing[0]['service'], 'service' => $failing[0]['service'], 'anchor' => 'diagnose']];
    }

    private static function statusOf(ServiceSnapshot $service): Status
    {
        return in_array($service->status, [ServiceStatus::Unreachable, ServiceStatus::ChannelMissing], true) ? Status::Critical : Status::Info;
    }

    private static function labelOf(ServiceSnapshot $service): string
    {
        return match ($service->status) {
            ServiceStatus::Unreachable => 'does not answer',
            ServiceStatus::ChannelMissing => 'request channel missing',
            default => $service->hasSnapshot() ? sprintf('last answered %d s ago', (int) $service->ageSeconds) : 'no answer yet',
        };
    }

    /**
     * @param list<string> $names
     */
    private static function names(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
    }
}
