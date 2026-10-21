<?php

declare(strict_types=1);

namespace App\Settings;

final class PulseEnvironment
{
    public const VARIABLES = [
        'ECOTONE_LICENCE_KEY',
        'PULSE_SERVICE_NAME',
        'PULSE_CHANNEL',
        'PULSE_TRANSPORT_DSN',
        'PULSE_SERVICES',
        'PULSE_REPLY_TIMEOUT',
        'PULSE_RENDER_WAIT',
        'PULSE_CACHE_DIR',
        'PULSE_SETUP_DATABASE',
    ];

    /**
     * @return array<string, string>
     */
    public static function read(): array
    {
        $environment = [];
        foreach (self::VARIABLES as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
