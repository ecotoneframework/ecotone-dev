<?php

declare(strict_types=1);

namespace App\DeadLetter;

use App\Snapshot\ServiceSnapshot;

final class DeadLetterOverview
{
    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return list<array{service: string, status: ServiceSnapshot, configured: bool, total: int, groups: list<array<string, mixed>>, entries: list<array<string, mixed>>}>
     */
    public static function of(array $snapshots, DeadLetterFilter $filter): array
    {
        $services = [];
        foreach ($snapshots as $snapshot) {
            if (! $filter->selectsService($snapshot->service)) {
                continue;
            }
            $deadLetters = $snapshot->snapshot['deadLetters'] ?? null;
            $configured = is_array($deadLetters);
            $services[] = [
                'service' => $snapshot->service,
                'status' => $snapshot,
                'configured' => $configured,
                'total' => $configured ? (int) ($deadLetters['total'] ?? 0) : 0,
                'groups' => $configured ? array_values($deadLetters['groups'] ?? []) : [],
                'entries' => $configured ? array_values(array_filter($deadLetters['recent'] ?? [], $filter->matches(...))) : [],
            ];
        }

        return $services;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     */
    public static function total(array $snapshots): int
    {
        $names = self::withoutServicesSharingAnEarlierStore($snapshots, array_map(static fn (ServiceSnapshot $snapshot) => $snapshot->service, $snapshots));

        return array_sum(array_map(static fn (ServiceSnapshot $snapshot) => in_array($snapshot->service, $names, true) ? (int) $snapshot->deadLetterTotal() : 0, $snapshots));
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @param array<string, mixed> $entry
     */
    public static function similar(array $snapshots, string $service, array $entry): int
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->service !== $service) {
                continue;
            }
            foreach ($snapshot->snapshot['deadLetters']['groups'] ?? [] as $group) {
                if (($group['messageName'] ?? null) === ($entry['messageName'] ?? null) && ($group['exceptionClass'] ?? null) === ($entry['exceptionClass'] ?? null)) {
                    return max(0, (int) ($group['count'] ?? 0) - 1);
                }
            }
        }

        return 0;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return array<string, mixed>|null
     */
    public static function entry(array $snapshots, string $service, string $messageId): ?array
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->service !== $service) {
                continue;
            }
            foreach ($snapshot->snapshot['deadLetters']['recent'] ?? [] as $entry) {
                if (($entry['messageId'] ?? null) === $messageId) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return array<string, list<string>>
     */
    public static function channelsByService(array $snapshots): array
    {
        $channels = [];
        foreach ($snapshots as $snapshot) {
            $names = [];
            foreach ($snapshot->snapshot['channels'] ?? [] as $channel) {
                if (is_array($channel) && is_string($channel['name'] ?? null)) {
                    $names[] = $channel['name'];
                }
            }
            sort($names);
            $channels[$snapshot->service] = array_values(array_unique($names));
        }

        return $channels;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return list<list<string>>
     */
    public static function servicesSharingAStore(array $snapshots): array
    {
        $groups = [];
        $firstServiceOfId = [];
        foreach ($snapshots as $snapshot) {
            $owners = [];
            foreach ($snapshot->snapshot['deadLetters']['recent'] ?? [] as $entry) {
                $id = $entry['messageId'] ?? null;
                if (is_string($id) && isset($firstServiceOfId[$id])) {
                    $owners[$firstServiceOfId[$id]] = true;
                }
            }
            foreach ($snapshot->snapshot['deadLetters']['recent'] ?? [] as $entry) {
                if (is_string($entry['messageId'] ?? null)) {
                    $firstServiceOfId[$entry['messageId']] ??= $snapshot->service;
                }
            }
            foreach (array_keys($owners) as $owner) {
                $groups[$owner][] = $snapshot->service;
            }
        }

        return array_map(static fn (string $owner, array $others) => [$owner, ...array_values(array_unique($others))], array_keys($groups), array_values($groups));
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @param list<string> $services
     * @return list<string>
     */
    public static function withoutServicesSharingAnEarlierStore(array $snapshots, array $services): array
    {
        $duplicates = [];
        foreach (self::servicesSharingAStore($snapshots) as $group) {
            array_push($duplicates, ...array_slice($group, 1));
        }

        return array_values(array_diff($services, $duplicates));
    }
}
