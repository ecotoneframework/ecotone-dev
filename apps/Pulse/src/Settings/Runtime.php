<?php

declare(strict_types=1);

namespace App\Settings;

enum Runtime: string
{
    case Php = 'php';
    case Swoole = 'swoole';

    /**
     * @param array<string, mixed> $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        $value = $environment['PULSE_RUNTIME'] ?? null;
        $value = is_string($value) && trim($value) !== '' ? trim($value) : self::Php->value;

        return self::tryFrom($value) ?? throw new PulseConfigurationException(sprintf('PULSE_RUNTIME "%s" is not a runtime Pulse runs on. Set it to "php", the default, which serves Pulse with FrankenPHP and consumes each reply channel in its own process, or to "swoole", which serves Pulse with OpenSwoole and consumes each reply channel on PULSE_CONSUMER_COROUTINES coroutines.', $value));
    }

    /**
     * @param array<string, mixed> $environment
     */
    public static function consumerCoroutines(array $environment): int
    {
        return self::wholeNumberFromOne($environment, 'PULSE_CONSUMER_COROUTINES', 6, 'coroutines consuming each reply channel in one process');
    }

    /**
     * @param array<string, mixed> $environment
     */
    public static function httpWorkers(array $environment): int
    {
        return self::wholeNumberFromOne($environment, 'PULSE_HTTP_WORKERS', 4, 'OpenSwoole worker processes serving HTTP');
    }

    /**
     * @param array<string, mixed> $environment
     */
    private static function wholeNumberFromOne(array $environment, string $name, int $default, string $meaning): int
    {
        $value = $environment[$name] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return $default;
        }
        if (preg_match('/^[1-9][0-9]{0,3}$/', trim($value)) !== 1) {
            throw new PulseConfigurationException(sprintf('%s "%s" is not a number of %s. Set it to a whole number from 1 to 9999, for example %d, which is the default. It is read only with PULSE_RUNTIME=swoole.', $name, $value, $meaning, $default));
        }

        return (int) trim($value);
    }
}
