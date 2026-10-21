<?php

declare(strict_types=1);

namespace App\Graph;

final class ShortLabel
{
    private const MAX_LENGTH = 40;

    public static function of(string $label): string
    {
        if (str_contains($label, '::')) {
            [$class, $method] = explode('::', $label, 2);
            $class = str_contains($class, '@anonymous') ? 'class@anonymous' : (string) preg_replace('#^.*[\\\\/]#', '', $class);

            return self::truncated($class . '::' . $method);
        }

        return self::truncated($label);
    }

    private static function truncated(string $label): string
    {
        return mb_strlen($label) <= self::MAX_LENGTH ? $label : mb_substr($label, 0, self::MAX_LENGTH - 1) . '…';
    }
}
