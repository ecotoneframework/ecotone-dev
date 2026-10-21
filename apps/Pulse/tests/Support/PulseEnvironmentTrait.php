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
        $environment = [
            'ECOTONE_LICENCE_KEY' => LicenceTesting::VALID_LICENCE,
            'PULSE_SERVICE_NAME' => 'ecotone_pulse',
            'PULSE_CHANNEL' => 'ecotone_pulse',
            'PULSE_TRANSPORT_DSN' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16',
            'PULSE_SERVICES' => 'orders:orders_channel,billing:billing_channel',
            'PULSE_REPLY_TIMEOUT' => '10',
            'PULSE_RENDER_WAIT' => '0',
            'PULSE_INTERNAL_DATABASE_PATH' => sys_get_temp_dir() . '/pulse-internal-' . uniqid() . '.sqlite',
            ...$overrides,
        ];

        foreach (PulseEnvironment::VARIABLES as $name) {
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
}
