<?php

declare(strict_types=1);

namespace App\Graph;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(404)]
final class UnknownFold extends InvalidArgumentException
{
    public function __construct(string $requested)
    {
        parent::__construct(sprintf('Cannot fold "%s": only channels can be folded into arrows. Use fold=channels, or remove the fold parameter to draw every channel.', $requested));
    }
}
