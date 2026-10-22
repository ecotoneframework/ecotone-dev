<?php

declare(strict_types=1);

namespace App\Setup;

use App\Snapshot\ServiceSnapshot;

final readonly class SetupStatus
{
    private const DATABASE_SETUP = 'ecotone:migration:database:setup';
    private const CHANNEL_SETUP = 'ecotone:migration:channel:setup';

    /**
     * @param list<SetupItem> $items
     * @param array<string, string> $sectionErrors
     */
    private function __construct(public array $items, public array $sectionErrors)
    {
    }

    public static function of(ServiceSnapshot $service): self
    {
        $framework = is_string($service->snapshot['overview']['framework'] ?? null) ? $service->snapshot['overview']['framework'] : 'lite';
        $items = [];
        foreach (['tables', 'tenantTables'] as $list) {
            foreach (is_array($service->snapshot['setup'][$list] ?? null) ? $service->snapshot['setup'][$list] : [] as $table) {
                if (is_array($table) && is_string($table['feature'] ?? null) && is_string($table['connection'] ?? null)) {
                    $items[] = new SetupItem('table', $table['feature'], $table['connection'], is_bool($table['initialized'] ?? null) ? $table['initialized'] : null, is_string($table['error'] ?? null) ? $table['error'] : null, self::tableFix($framework, $table['feature'], $table['connection']));
                }
            }
        }
        foreach (is_array($service->snapshot['channels'] ?? null) ? $service->snapshot['channels'] : [] as $channel) {
            if (is_array($channel) && is_string($channel['name'] ?? null) && ! is_bool($channel['setUp'] ?? null) && is_string($channel['probeError'] ?? null)) {
                $items[] = new SetupItem('channel', $channel['name'], null, null, $channel['probeError'], null);
            } elseif (is_array($channel) && is_string($channel['name'] ?? null) && is_bool($channel['setUp'] ?? null)) {
                $items[] = new SetupItem('channel', $channel['name'], null, $channel['setUp'], null, $channel['setUp'] ? null : self::command($framework, self::CHANNEL_SETUP, ['initialize' => true, 'channel' => [$channel['name']]]));
            }
        }
        $errors = is_array($service->snapshot['sectionErrors'] ?? null) ? array_filter($service->snapshot['sectionErrors'], is_string(...)) : [];

        return new self($items, $errors);
    }

    /**
     * @return list<SetupItem>
     */
    public function missing(): array
    {
        return array_values(array_filter($this->items, static fn (SetupItem $item): bool => $item->isMissing()));
    }

    /**
     * @return list<SetupItem>
     */
    public function unknown(): array
    {
        return array_values(array_filter($this->items, static fn (SetupItem $item): bool => $item->isUnknown()));
    }

    public function isAllClear(): bool
    {
        return $this->missing() === [] && $this->unknown() === [] && $this->sectionErrors === [];
    }

    private static function tableFix(string $framework, string $feature, string $connection): string
    {
        return self::command($framework, self::DATABASE_SETUP, ['initialize' => true, 'feature' => [$feature], 'connection' => $connection]);
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     */
    private static function command(string $framework, string $name, array $options): string
    {
        if ($framework === 'lite') {
            return sprintf('$ecotone->runConsoleCommand(%s, %s);', var_export($name, true), self::phpArray($options));
        }

        $arguments = [];
        foreach ($options as $option => $value) {
            foreach (is_array($value) ? $value : [$value] as $single) {
                $arguments[] = $single === true ? '--' . $option : sprintf('--%s=%s', $option, preg_match('/^[A-Za-z0-9_.-]+$/', $single) === 1 ? $single : "'" . $single . "'");
            }
        }

        return implode(' ', [match ($framework) {
            'laravel' => 'php artisan',
            'tempest' => './tempest',
            default => 'bin/console',
        }, $name, ...$arguments]);
    }

    /**
     * @param array<string, bool|string|list<string>> $options
     */
    private static function phpArray(array $options): string
    {
        return '[' . implode(', ', array_map(static fn (string $option, bool|string|array $value): string => sprintf("'%s' => %s", $option, is_array($value) ? '[' . implode(', ', array_map(static fn (string $single): string => var_export($single, true), $value)) . ']' : var_export($value, true)), array_keys($options), $options)) . ']';
    }
}
