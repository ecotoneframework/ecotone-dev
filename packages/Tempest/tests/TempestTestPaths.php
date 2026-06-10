<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest;

/**
 * licence Apache-2.0
 */
final class TempestTestPaths
{
    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function appRoot(): string
    {
        return self::packageRoot() . '/tests/app';
    }

    public static function srcPath(): string
    {
        return self::packageRoot() . '/src';
    }

    public static function fixturePath(): string
    {
        return self::packageRoot() . '/tests/Fixture';
    }

    public static function discoveryRoot(): string
    {
        $directory = self::packageRoot();

        while ($directory !== dirname($directory)) {
            if (is_file($directory . '/vendor/composer/installed.json')) {
                return $directory;
            }

            $directory = dirname($directory);
        }

        return self::packageRoot();
    }
}
