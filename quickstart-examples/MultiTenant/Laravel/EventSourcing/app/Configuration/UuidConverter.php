<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Messaging\Attribute\Converter;
use Ramsey\Uuid\UuidInterface;
use Ramsey\Uuid\Uuid;

final readonly class UuidConverter
{
    #[Converter]
    public function fromString(string $uuid): UuidInterface
    {
        return Uuid::fromString($uuid);
    }

    #[Converter]
    public function toString(UuidInterface $uuid): string
    {
        return $uuid->toString();
    }
}