<?php

declare(strict_types=1);

namespace App\Graph;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(404)]
final class UnknownCollapse extends InvalidArgumentException
{
    /**
     * @param list<string> $available
     */
    public function __construct(string $requested, array $available)
    {
        sort($available);

        parent::__construct(sprintf('Cannot collapse "%s": it is not among the services that answered. Choose one of: %s; or remove collapse[]=%s from the address to keep the other services as they are.', $requested, implode(', ', $available), $requested));
    }
}
