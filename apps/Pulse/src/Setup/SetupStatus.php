<?php

declare(strict_types=1);

namespace App\Setup;

use App\Snapshot\ServiceSnapshot;
use App\Ui\Status;

final readonly class SetupStatus
{
    private const DATABASE_SETUP = 'ecotone:migration:database:setup';
    private const PROJECTION_INIT = 'ecotone:projection:init';
    private const CHANNEL_SETUP = 'ecotone:migration:channel:setup';

    /**
     * @param list<SetupItem> $items
     * @param array<string, string> $sectionErrors
     * @param list<PackageSetup> $packages
     */
    private function __construct(public array $items, public array $sectionErrors, public array $packages)
    {
    }

    public static function of(ServiceSnapshot $service): self
    {
        $framework = is_string($service->snapshot['overview']['framework'] ?? null) ? $service->snapshot['overview']['framework'] : 'lite';
        $items = [];
        foreach (['tables', 'tenantTables'] as $list) {
            foreach (is_array($service->snapshot['setup'][$list] ?? null) ? $service->snapshot['setup'][$list] : [] as $table) {
                if (is_array($table) && is_string($table['feature'] ?? null) && is_string($table['connection'] ?? null)) {
                    $items[] = new SetupItem('table', $table['feature'], $table['connection'], is_bool($table['initialized'] ?? null) ? $table['initialized'] : null, is_string($table['error'] ?? null) ? $table['error'] : null, self::tableFix($framework, $table['feature'], $table['connection']), is_string($table['package'] ?? null) ? $table['package'] : null);
                }
            }
        }
        foreach (is_array($service->snapshot['channels'] ?? null) ? $service->snapshot['channels'] : [] as $channel) {
            if (is_array($channel) && is_string($channel['name'] ?? null) && ! is_bool($channel['setUp'] ?? null) && is_string($channel['probeError'] ?? null)) {
                $items[] = new SetupItem('channel', $channel['name'], null, null, $channel['probeError'], null, null);
            } elseif (is_array($channel) && is_string($channel['name'] ?? null) && is_bool($channel['setUp'] ?? null)) {
                $items[] = new SetupItem('channel', $channel['name'], null, $channel['setUp'], null, $channel['setUp'] ? null : self::command($framework, self::CHANNEL_SETUP, ['initialize' => true, 'channel' => [$channel['name']]]), null);
            }
        }
        $errors = is_array($service->snapshot['sectionErrors'] ?? null) ? array_filter($service->snapshot['sectionErrors'], is_string(...)) : [];

        return new self($items, $errors, self::packagesOf($service, $items));
    }

    /**
     * @param list<SetupItem> $items
     * @return list<PackageSetup>
     */
    private static function packagesOf(ServiceSnapshot $service, array $items): array
    {
        $packages = [];
        foreach (is_array($service->snapshot['packages'] ?? null) ? $service->snapshot['packages'] : [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['composerPackage'] ?? null)) {
                continue;
            }
            $packages[] = new PackageSetup(
                name: $package['name'],
                composerPackage: $package['composerPackage'],
                version: is_string($package['version'] ?? null) ? $package['version'] : null,
                tables: array_values(array_filter($items, static fn (SetupItem $item): bool => $item->kind === 'table' && $item->package === $package['name'])),
                features: array_values(array_filter(array_map(static fn (mixed $feature): ?Feature => is_array($feature) ? Feature::fromSnapshot($feature) : null, is_array($package['features'] ?? null) ? $package['features'] : []))),
            );
        }

        return $packages;
    }

    /**
     * @return list<SetupItem>
     */
    public function itemsOutsidePackages(): array
    {
        $packageNames = array_map(static fn (PackageSetup $package): string => $package->name, $this->packages);

        return array_values(array_filter($this->items, static fn (SetupItem $item): bool => $item->kind !== 'table' || ! in_array($item->package, $packageNames, true)));
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

    public function status(): Status
    {
        return match (true) {
            $this->missing() !== [] => Status::Critical,
            $this->unknown() !== [] || $this->sectionErrors !== [] => Status::Warning,
            default => Status::Ok,
        };
    }

    public function state(): string
    {
        $parts = array_filter([
            $this->missing() === [] ? null : sprintf('%d missing', count($this->missing())),
            $this->unknown() === [] ? null : sprintf('%d could not be checked', count($this->unknown())),
            $this->sectionErrors === [] ? null : sprintf('%d %s could not be read', count($this->sectionErrors), count($this->sectionErrors) === 1 ? 'section' : 'sections'),
        ]);

        return $parts === [] ? 'all set up' : implode(', ', $parts);
    }

    public function sentence(): string
    {
        $features = array_merge(...array_map(static fn (PackageSetup $package): array => $package->features, $this->packages));
        $on = count(array_filter($features, static fn (Feature $feature): bool => $feature->enabled));
        $tables = count(array_filter($this->items, static fn (SetupItem $item): bool => $item->kind === 'table'));
        $channels = count($this->items) - $tables;
        $items = implode(' and ', array_filter([
            $tables === 0 ? null : sprintf('%d %s', $tables, $tables === 1 ? 'table' : 'tables'),
            $channels === 0 ? null : sprintf('%d %s', $channels, $channels === 1 ? 'channel' : 'channels'),
        ]));
        $setup = match (true) {
            $this->items === [] && $this->sectionErrors === [] => 'no table or channel to check',
            $this->isAllClear() => $items . ' set up',
            default => ($items === '' ? 'setup' : $items) . ': ' . $this->state(),
        };

        return sprintf('%d %s on · %d not in use · %s', $on, $on === 1 ? 'feature' : 'features', count($features) - $on, $setup);
    }

    public static function projectionInit(string $framework, string $projection): string
    {
        if ($framework === 'lite') {
            return sprintf('$ecotone->runConsoleCommand(%s, [%s => %s]);', var_export(self::PROJECTION_INIT, true), var_export('name', true), var_export($projection, true));
        }

        return sprintf('%s %s %s', match ($framework) {
            'laravel' => 'php artisan',
            'tempest' => './tempest',
            default => 'bin/console',
        }, self::PROJECTION_INIT, preg_match('/^[A-Za-z0-9_.-]+$/', $projection) === 1 ? $projection : "'" . $projection . "'");
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
