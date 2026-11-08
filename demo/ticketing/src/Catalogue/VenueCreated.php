<?php

declare(strict_types=1);

namespace App\Catalogue;

final readonly class VenueCreated
{
    public function __construct(public string $venueId, public string $name)
    {
    }
}
