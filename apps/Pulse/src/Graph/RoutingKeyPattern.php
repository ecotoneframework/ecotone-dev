<?php

declare(strict_types=1);

namespace App\Graph;

final class RoutingKeyPattern
{
    public static function matches(string $pattern, string $routingKey): bool
    {
        $pattern = str_replace('\\', '\\\\', $pattern);
        $pattern = str_replace('.', '\\.', $pattern);
        $pattern = str_replace('*', '.*', $pattern);

        return (bool) preg_match('#^' . $pattern . '$#i', $routingKey);
    }
}
