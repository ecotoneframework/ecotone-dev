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
        'PULSE_AWAIT_TIMEOUT',
        'PULSE_REFRESH_INTERVAL',
        'PULSE_INTERNAL_DATABASE_PATH',
    ];

    private const CONNECTION_VARIABLE = '/^PULSE_CONNECTION_[A-Z0-9]+(?:_[A-Z0-9]+)*_(?:DSN|REPLY_CHANNEL)(?:_FILE)?$/';
    private const SECRET_VARIABLES = ['ECOTONE_LICENCE_KEY', 'PULSE_TRANSPORT_DSN', 'APP_SECRET'];
    private const DEFAULT_LICENCE_SECRET_FILE = '/run/secrets/ecotone_licence_key';

    /**
     * @return array<string, string>
     */
    public static function read(): array
    {
        self::exportSecretFiles();

        $environment = [];
        foreach (self::names() as $name) {
            $value = self::value($name);
            if ($value !== null) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /**
     * @return list<string>
     */
    private static function names(): array
    {
        $connection = [];
        foreach ([...array_keys($_SERVER), ...array_keys($_ENV), ...array_keys(getenv())] as $name) {
            if (preg_match(self::CONNECTION_VARIABLE, (string) $name) === 1) {
                $connection[] = (string) preg_replace('/_FILE$/', '', (string) $name);
            }
        }

        return [...self::VARIABLES, ...array_values(array_unique($connection))];
    }

    private static function exportSecretFiles(): void
    {
        $secrets = self::SECRET_VARIABLES;
        foreach (self::names() as $name) {
            if (str_ends_with($name, '_DSN')) {
                $secrets[] = $name;
            }
        }

        foreach (array_unique($secrets) as $name) {
            if (self::value($name) !== null) {
                continue;
            }

            $fileVariable = $name . '_FILE';
            $file = self::value($fileVariable);
            if ($file === null && $name === 'ECOTONE_LICENCE_KEY' && is_readable(self::DEFAULT_LICENCE_SECRET_FILE)) {
                $file = self::DEFAULT_LICENCE_SECRET_FILE;
            }
            if ($file === null) {
                continue;
            }

            $content = is_readable($file) && ! is_dir($file) ? file_get_contents($file) : false;
            if ($content === false) {
                throw new PulseConfigurationException(sprintf('%s points to %s, which cannot be read. Mount the secret at that path, or unset %s and set %s instead.', $fileVariable, $file, $fileVariable, $name));
            }

            $_SERVER[$name] = $_ENV[$name] = trim($content);
            putenv($name . '=' . trim($content));
        }
    }

    private static function value(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
