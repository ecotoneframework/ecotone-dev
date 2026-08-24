<?php

namespace App\Conversion\Configuration;

use Ecotone\Api\Converter;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class UuidConverter
{
    #[Converter]
    public function to(string $uuid): UuidInterface
    {
        return Uuid::fromString($uuid);
    }
}