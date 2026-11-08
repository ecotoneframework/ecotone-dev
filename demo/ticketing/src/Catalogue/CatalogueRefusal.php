<?php

declare(strict_types=1);

namespace App\Catalogue;

use DomainException;

final class CatalogueRefusal extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
