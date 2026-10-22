<?php

declare(strict_types=1);

namespace App\Graph;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(404)]
final class UnknownServiceFilter extends InvalidArgumentException
{
    /**
     * @param list<string> $available
     */
    public function __construct(string $requested, array $available)
    {
        parent::__construct($available === []
            ? sprintf('No service has answered yet, so "%s" cannot be shown. Remove the service parameter to see every service once they answer.', $requested)
            : sprintf('Service "%s" is not among the services that answered. Choose one of: %s, or remove the service parameter to see all services.', $requested, implode(', ', $available)));
    }
}
