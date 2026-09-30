<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

return static function (string $monorepoDirectory): array {
    $acceptedDivergences = [
        '<ini name="memory_limit">' => [
            'packages' => '*',
            'reason' => 'the root raises memory_limit to 384M for the whole suite in one process; whether each package needs it is undecided',
        ],
        '<server name="KERNEL_CLASS">' => [
            'packages' => ['Amqp', 'DataProtection', 'Dbal', 'Ecotone', 'Enqueue', 'JmsConverter', 'Kafka', 'OpenTelemetry', 'PdoEventSourcing', 'Redis', 'Sqs', 'Tempest', 'Laravel'],
            'reason' => 'only packages/Symfony boots a kernel through KernelTestCase; whether every package should carry it is undecided',
        ],
        '<server name="APP_SECRET">' => [
            'packages' => ['Amqp', 'DataProtection', 'Dbal', 'Ecotone', 'Enqueue', 'JmsConverter', 'Kafka', 'OpenTelemetry', 'PdoEventSourcing', 'Redis', 'Sqs', 'Tempest', 'Laravel'],
            'reason' => 'only packages/Symfony reads APP_SECRET (config/packages/framework.yaml); whether every package should carry it is undecided',
        ],
        '<env name="APP_ENV">' => [
            'packages' => ['Amqp', 'DataProtection', 'Dbal', 'Ecotone', 'Enqueue', 'JmsConverter', 'Kafka', 'OpenTelemetry', 'PdoEventSourcing', 'Redis', 'Sqs', 'Tempest'],
            'reason' => 'only the Laravel and Symfony test applications read APP_ENV; whether every package should carry it is undecided',
        ],
        '<env name="KERNEL_CLASS">' => [
            'packages' => ['Laravel'],
            'reason' => 'a Symfony-skeleton leftover no Laravel test reads (none extends KernelTestCase); the root sets it as <server> with Ecotone\SymfonyBundle\App\Kernel',
        ],
        '<env name="APP_SECRET">' => [
            'packages' => ['Laravel'],
            'reason' => 'a Symfony-skeleton leftover no Laravel test reads; which value is right is undecided',
        ],
        '<env name="APP_DEBUG">' => [
            'packages' => ['Laravel'],
            'reason' => 'read by the Laravel test applications\' config/app.php; the root full suite runs them with debug off',
        ],
        '<ini name="error_reporting">' => [
            'packages' => ['Laravel'],
            'reason' => 'Laravel-only; setting it at root changes error reporting for every package',
        ],
        '<env name="DATABASE_URL">' => [
            'packages' => ['Laravel'],
            'reason' => 'read by packages/Laravel/tests/Application/config/database.php; the root full suite runs without it',
        ],
        '<env name="SHELL_VERBOSITY">' => [
            'packages' => ['Laravel'],
            'reason' => 'silences artisan output in the Laravel package run only; setting it at root also reaches Symfony console tests',
        ],
    ];
    $behaviourAttributeDefaults = ['backupGlobals' => 'false', 'backupStaticProperties' => 'false', 'processIsolation' => 'false'];

    $phpunitConfiguration = static fn (string $directory): string => is_file($directory . '/phpunit.xml') ? $directory . '/phpunit.xml' : $directory . '/phpunit.xml.dist';
    $settings = static function (string $configurationPath) use ($behaviourAttributeDefaults): array {
        $configuration = simplexml_load_file($configurationPath);
        $settings = [];
        foreach ($behaviourAttributeDefaults as $attribute => $default) {
            $value = isset($configuration[$attribute]) ? (string) $configuration[$attribute] : $default;
            $settings["<phpunit {$attribute}>"] = "<phpunit {$attribute}=\"{$value}\">";
        }
        foreach ($configuration->xpath('/phpunit/php/*') as $entry) {
            $key = isset($entry['name']) ? "<{$entry->getName()} name=\"{$entry['name']}\">" : "<{$entry->getName()}>";
            $force = isset($entry['force']) && (string) $entry['force'] === 'true' ? ' force="true"' : '';
            $name = isset($entry['name']) ? " name=\"{$entry['name']}\"" : '';
            $value = isset($entry['value']) ? " value=\"{$entry['value']}\"" : '';
            $settings[$key] = "<{$entry->getName()}{$name}{$value}{$force} />";
        }

        return $settings;
    };

    $rootConfiguration = $phpunitConfiguration($monorepoDirectory);
    $rootConfigurationName = basename($rootConfiguration);
    $rootSettings = $settings($rootConfiguration);
    $isAccepted = static fn (string $key, string $package): bool => isset($acceptedDivergences[$key])
        && ($acceptedDivergences[$key]['packages'] === '*' || in_array($package, $acceptedDivergences[$key]['packages'], true));

    $divergences = [];
    $acceptedAndDiverging = [];
    foreach (glob($monorepoDirectory . '/packages/*', GLOB_ONLYDIR) as $packagePath) {
        $package = basename($packagePath);
        $packageConfiguration = $phpunitConfiguration($packagePath);
        if (! is_file($packageConfiguration)) {
            continue;
        }
        $packageConfigurationName = 'packages/' . $package . '/' . basename($packageConfiguration);
        $packageSettings = $settings($packageConfiguration);

        foreach (array_unique([...array_keys($rootSettings), ...array_keys($packageSettings)]) as $key) {
            $rootSetting = $rootSettings[$key] ?? null;
            $packageSetting = $packageSettings[$key] ?? null;
            if ($rootSetting === $packageSetting) {
                continue;
            }
            if ($isAccepted($key, $package)) {
                $acceptedAndDiverging[$key][] = $package;
                continue;
            }

            $divergences[] = "<php> environment: {$packageConfigurationName} " . ($packageSetting === null ? 'does not set ' . $key : 'sets ' . $packageSetting)
                . ", the root {$rootConfigurationName} " . ($rootSetting === null ? 'does not set ' . $key : 'sets ' . $rootSetting) . ".\n"
                . "   packages/{$package} tests run under a different environment in the root full suite than in their own suite, so a test can pass under one runner and fail under the other. "
                . (str_starts_with($key, '<phpunit ')
                    ? 'An attribute left out of <phpunit> takes its PHPUnit default (false), and each of these changes test isolation: backupGlobals="true", for one, restores $GLOBALS, $_SERVER and $_ENV after every test, so without it a test that changes them leaks into the next.'
                    : '<env> and <server> are different superglobals, and force="true" decides whether a value the shell or container already exports wins.')
                . "\n   Fix: " . match (true) {
                    str_starts_with($key, '<phpunit ') => 'set ' . substr($rootSetting, strlen('<phpunit '), -1) . " on the <phpunit> element of {$packageConfigurationName} — the root full suite already runs these tests with it.",
                    $packageSetting === null => "add {$rootSetting} to the <php> block of {$packageConfigurationName} — the root full suite already runs these tests with it.",
                    $rootSetting === null => "remove {$packageSetting} from {$packageConfigurationName} if no test reads it, or add it to {$rootConfigurationName} and to every package configuration.",
                    default => "make both {$rootSetting} — the root value is the one every package's tests already pass under in the root full suite.",
                }
            . "\n   If the difference is deliberate, record it with its reason in \$acceptedDivergences in bin/suite-parity/php-environment.php.";
        }
    }

    foreach ($acceptedDivergences as $key => $acceptance) {
        $acceptedPackages = $acceptance['packages'] === '*' ? null : $acceptance['packages'];
        $stillDiverging = $acceptedAndDiverging[$key] ?? [];
        $noLongerDiverging = $acceptedPackages === null ? ($stillDiverging === [] ? ['*'] : []) : array_diff($acceptedPackages, $stillDiverging);
        if ($noLongerDiverging === []) {
            continue;
        }

        $divergences[] = "<php> environment: \$acceptedDivergences in bin/suite-parity/php-environment.php accepts {$key} for " . implode(', ', $noLongerDiverging)
            . ", but it no longer diverges there.\n"
            . '   Fix: remove ' . ($acceptedPackages === null || count($noLongerDiverging) === count($acceptedPackages) ? "the {$key} entry" : implode(', ', $noLongerDiverging) . " from the {$key} entry")
            . ' so the next divergence of that setting fails the build again.';
    }

    return $divergences;
};
