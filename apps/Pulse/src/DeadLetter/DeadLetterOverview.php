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
            foreach ($snapshot->snapshot['flowGraph']['nodes'] ?? [] as $node) {
                if (($node['kind'] ?? null) === 'channel' && is_string($node['label'] ?? null)) {
                    $names[] = $node['label'];
                }
            }
            sort($names);
            $channels[$snapshot->service] = array_values(array_unique($names));
        }

        return $channels;
    }
}
