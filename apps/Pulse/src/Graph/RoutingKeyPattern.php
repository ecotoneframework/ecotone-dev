<?php

declare(strict_types=1);

namespace App\Graph;

final class RoutingKeyPattern
{
    public static function matches(string $pattern, string $routingKey): bool
    {
        return preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/', $routingKey) === 1;
    }
}
