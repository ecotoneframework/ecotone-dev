<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Settings\PulseEnvironment;
use Ecotone\Test\LicenceTesting;

trait PulseEnvironmentTrait
{
    /**
     * @param array<string, string|null> $overrides
     */
    private function configurePulse(array $overrides = []): void
    {
        $this->resetSharedState();
        $environment = [
            'ECOTONE_LICENCE_KEY' => LicenceTesting::VALID_LICENCE,
            'PULSE_VERSION' => 'test',
            'PULSE_SERVICE_NAME' => 'ecotone_pulse',
            'PULSE_CHANNEL' => 'ecotone_pulse',
            'PULSE_TRANSPORT_DSN' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16',
            'PULSE_SERVICES' => 'orders:orders_channel,billing:billing_channel',
            'PULSE_REPLY_TIMEOUT' => '10',
            'PULSE_AWAIT_TIMEOUT' => '0',
            'PULSE_INTERNAL_DATABASE_PATH' => sys_get_temp_dir() . '/pulse-internal-' . getmypid() . '.sqlite',
            ...$overrides,
        ];

        $connectionVariables = array_filter(
            array_unique([...array_keys($_SERVER), ...array_keys($_ENV), ...array_keys(getenv()), ...array_keys($environment)]),
            static fn (int|string $name) => str_starts_with((string) $name, 'PULSE_CONNECTION_')
        );

        foreach ([...PulseEnvironment::VARIABLES, ...$connectionVariables] as $name) {
            $value = $environment[$name] ?? null;
            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
                continue;
            }
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }
    }

    private function resetSharedState(): void
    {
        $dsn = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16';
        $connection = OpenedConnections::factory($dsn)->createContext()->getDbalConnection();
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        OpenedConnections::closeAll();

        foreach (glob(sys_get_temp_dir() . '/pulse-internal-' . getmypid() . '.sqlite*') ?: [] as $file) {
            unlink($file);
        }
    }
}
