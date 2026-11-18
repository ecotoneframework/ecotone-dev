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
}
