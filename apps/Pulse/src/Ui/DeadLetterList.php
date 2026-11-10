<?php

declare(strict_types=1);

namespace App\Ui;

use App\DeadLetter\DeadLetterFilter;
use App\Snapshot\ServiceSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class DeadLetterList
{
    public const STATE_FAILURES = 'failures';
    public const STATE_FILTERED_OUT = 'filtered-out';
    public const STATE_QUIET = 'quiet';
    public const STATE_WAITING = 'waiting';
    public const STATE_NOT_CONFIGURED = 'not-configured';

    private const STATE_ORDER = [self::STATE_FAILURES, self::STATE_WAITING, self::STATE_FILTERED_OUT, self::STATE_QUIET, self::STATE_NOT_CONFIGURED];

    /**
     * @param list<array<string, mixed>> $services
     * @param list<array{text: string, service?: string}> $verdictParts
     */
    private function __construct(
        public array $services,
        public Status $verdictStatus,
        public array $verdictParts,
        public ?string $verdictReason,
        public bool $filtersOpen,
        public bool $narrowsFailures,
    ) {
    }

    /**
     * @param list<array{service: string, name: string, tenant: ?string, status: ServiceSnapshot, configured: bool, total: int, groups: list<array<string, mixed>>}> $services
     * @param list<list<string>> $sharedStores
     */
    public static function of(array $services, DeadLetterFilter $filter, array $sharedStores): self
    {
        $sections = array_map(static fn (array $service): array => self::section($service, $filter), $services);
        usort($sections, static fn (array $left, array $right): int => [array_search($left['state'], self::STATE_ORDER, true), $right['latestFailure']] <=> [array_search($right['state'], self::STATE_ORDER, true), $left['latestFailure']]);

        $duplicates = [];
        foreach ($sharedStores as $group) {
            array_push($duplicates, ...array_slice($group, 1));
        }
        $counted = array_values(array_filter($sections, static fn (array $section): bool => ! in_array($section['service'], $duplicates, true)));
        $narrows = $filter->messageName !== '' || $filter->exceptionClass !== '' || $filter->failureId !== '' || $filter->narrowsEntries();

        return new self(
            $sections,
            self::statusOf($counted),
            $narrows ? self::filteredParts($counted, $filter) : self::parts($counted),
            self::reasonOf($counted),
            $filter->messageName !== '' || $filter->exceptionClass !== '' || $filter->failureId !== '' || $filter->failedFrom !== '' || $filter->failedTo !== '',
            $narrows,
        );
    }

    /**
     * @param array{service: string, name: string, tenant: ?string, status: ServiceSnapshot, configured: bool, total: int, groups: list<array<string, mixed>>} $service
     * @return array<string, mixed>
     */
    private static function section(array $service, DeadLetterFilter $filter): array
    {
        $groups = array_values(array_filter(
            array_map(static fn (array $group): array => self::group($group), $service['groups']),
            static fn (array $group): bool => $group['entries'] !== [] || ! $filter->narrowsEntries(),
        ));
        usort($groups, static fn (array $left, array $right): int => [(int) $right['count'], (string) $right['lastFailedAt'], (string) $left['failureId']] <=> [(int) $left['count'], (string) $left['lastFailedAt'], (string) $right['failureId']]);

        $state = match (true) {
            ! $service['status']->hasSnapshot() => self::STATE_WAITING,
            ! $service['configured'] => self::STATE_NOT_CONFIGURED,
            $groups !== [] => self::STATE_FAILURES,
            $service['total'] > 0 => self::STATE_FILTERED_OUT,
            default => self::STATE_QUIET,
        };

        return $service + [
            'state' => $state,
            'shownGroups' => $groups,
            'shownMessages' => array_sum(array_map(static fn (array $group): int => $filter->narrowsEntries() ? count($group['entries']) : (int) $group['count'], $groups)),
            'latestFailure' => array_reduce($groups, static fn (string $latest, array $group): string => max($latest, (string) $group['lastFailedAt']), ''),
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private static function group(array $group): array
    {
        $entries = is_array($group['entries'] ?? null) ? $group['entries'] : [];
        usort($entries, static fn (array $left, array $right): int => (string) ($right['failedAt'] ?? '') <=> (string) ($left['failedAt'] ?? ''));
        $file = is_string($group['exceptionFile'] ?? null) ? $group['exceptionFile'] : '';
        $line = (int) ($group['exceptionLine'] ?? 0);
        $first = self::utc($group['firstFailedAt'] ?? null);
        $last = self::utc($group['lastFailedAt'] ?? null);
        $channels = array_values(array_unique(array_filter(array_map(static fn (array $entry): string => is_string($entry['channel'] ?? null) ? $entry['channel'] : '', $entries))));

        return [
            ...$group,
            'entries' => $entries,
            'latest' => $entries[0] ?? null,
            'shortClass' => self::shortName((string) ($group['exceptionClass'] ?? '')),
            'shortName' => self::shortName((string) ($group['messageName'] ?? '')),
            'location' => $file === '' ? '' : basename($file) . ':' . $line,
            'locationTitle' => $file === '' ? '' : $file . ':' . $line,
            'channels' => $channels,
            'seen' => $first === $last ? 'failed ' . $last : 'first ' . $first . ' · last ' . $last,
        ];
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private static function statusOf(array $sections): Status
    {
        $statuses = [];
        foreach ($sections as $section) {
            $statuses[] = match ($section['state']) {
                self::STATE_FAILURES, self::STATE_FILTERED_OUT => $section['status']->deadLettersGrewBy > 0 ? Status::Critical : Status::Warning,
                self::STATE_WAITING => Status::Unknown,
                default => Status::Ok,
            };
        }

        return Status::worstOf(...$statuses);
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private static function reasonOf(array $sections): ?string
    {
        if (self::statusOf($sections) !== Status::Unknown) {
            return null;
        }
        $waiting = array_map(static fn (array $section): string => $section['service'], array_filter($sections, static fn (array $section): bool => $section['state'] === self::STATE_WAITING));

        return sprintf('Pulse has not received a snapshot of %s yet, so it cannot tell whether %s failed messages.', self::listOf($waiting), count($waiting) === 1 ? 'it holds' : 'they hold');
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array{text: string, service?: string}>
     */
    private static function parts(array $sections): array
    {
        $failing = array_values(array_filter($sections, static fn (array $section): bool => $section['state'] === self::STATE_FAILURES));
        if ($failing !== []) {
            $messages = array_sum(array_map(static fn (array $section): int => (int) $section['total'], $failing));
            $groups = array_sum(array_map(static fn (array $section): int => count($section['shownGroups']), $failing));
            $head = sprintf('%d failed %s in %d failure %s', $messages, $messages === 1 ? 'message' : 'messages', $groups, $groups === 1 ? 'group' : 'groups');

            return self::perService($head, $failing, static fn (array $section): int => (int) $section['total']);
        }

        $configured = array_map(static fn (array $section): string => $section['service'], array_filter($sections, static fn (array $section): bool => $section['state'] === self::STATE_QUIET));
        $notConfigured = array_map(static fn (array $section): string => $section['service'], array_filter($sections, static fn (array $section): bool => $section['state'] === self::STATE_NOT_CONFIGURED));
        $waiting = array_filter($sections, static fn (array $section): bool => $section['state'] === self::STATE_WAITING);
        if ($configured === [] && $waiting === []) {
            return [['text' => $sections === [] ? 'No service is configured in PULSE_SERVICES, so there are no dead letters to show.' : sprintf('No dead letters to show: %s %s not store dead letters.', self::listOf($notConfigured), count($notConfigured) === 1 ? 'does' : 'do')]];
        }
        if ($configured === []) {
            return [['text' => 'Waiting for the services to answer before Pulse can list their failed messages.']];
        }

        return [['text' => sprintf('No failed messages: dead letter storage is on in %s.', self::listOf($configured)) . ($notConfigured === [] ? '' : sprintf(' %s %s not store dead letters.', self::listOf($notConfigured), count($notConfigured) === 1 ? 'does' : 'do'))]];
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array{text: string, service?: string}>
     */
    private static function filteredParts(array $sections, DeadLetterFilter $filter): array
    {
        $matching = array_values(array_filter($sections, static fn (array $section): bool => $section['state'] === self::STATE_FAILURES));
        if ($matching !== []) {
            $messages = array_sum(array_map(static fn (array $section): int => (int) $section['shownMessages'], $matching));
            $groups = array_sum(array_map(static fn (array $section): int => count($section['shownGroups']), $matching));
            $noun = $filter->narrowsEntries() ? ($messages === 1 ? 'listed dead letter' : 'listed dead letters') : ($messages === 1 ? 'failed message' : 'failed messages');
            $head = sprintf('%d %s in %d failure %s %s the filter', $messages, $noun, $groups, $groups === 1 ? 'group' : 'groups', $messages === 1 ? 'matches' : 'match');

            return self::perService($head, $matching, static fn (array $section): int => (int) $section['shownMessages']);
        }

        $stored = array_values(array_filter($sections, static fn (array $section): bool => $section['state'] === self::STATE_FILTERED_OUT));
        if ($stored === []) {
            return [['text' => 'No dead letter matches the filter, and none is stored in the services shown.']];
        }
        $parts = [['text' => 'No dead letter matches the filter, but ']];
        foreach ($stored as $index => $section) {
            if ($index > 0) {
                $parts[] = ['text' => $index === count($stored) - 1 ? ' and ' : ', '];
            }
            $parts[] = ['text' => sprintf('%s holds %d failed %s', $section['service'], $section['total'], $section['total'] === 1 ? 'message' : 'messages'), 'service' => $section['service']];
        }
        $parts[] = ['text' => '.'];

        return $parts;
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @param callable(array<string, mixed>): int $count
     * @return list<array{text: string, service?: string}>
     */
    private static function perService(string $head, array $sections, callable $count): array
    {
        if (count($sections) === 1) {
            return [['text' => $head . ', all in '], ['text' => $sections[0]['service'], 'service' => $sections[0]['service']], ['text' => '.']];
        }
        $parts = [['text' => $head . ': ']];
        foreach ($sections as $index => $section) {
            if ($index > 0) {
                $parts[] = ['text' => ', '];
            }
            $parts[] = ['text' => sprintf('%d in %s', $count($section), $section['service']), 'service' => $section['service']];
        }
        $parts[] = ['text' => '.'];

        return $parts;
    }

    /**
     * @param array<string> $names
     */
    private static function listOf(array $names): string
    {
        $names = array_values($names);
        if (count($names) <= 1) {
            return implode('', $names);
        }

        return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[count($names) - 1];
    }

    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private static function utc(mixed $at): string
    {
        if (! is_string($at) || $at === '') {
            return 'at an unreported time';
        }
        try {
            return (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
        } catch (Throwable) {
            return $at;
        }
    }
}
