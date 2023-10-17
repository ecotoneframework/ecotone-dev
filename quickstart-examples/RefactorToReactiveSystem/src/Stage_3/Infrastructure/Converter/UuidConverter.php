<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Infrastructure\Converter;

use Ecotone\Messaging\Attribute\Converter;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class UuidConverter
{
    #[Converter]
    public function from(string $uuid): UuidInterface
    {
        return Uuid::fromString($uuid);
    }

    #[Converter]
    public function to(UuidInterface $uuid): string
    {
        return $uuid->toString();
    }
}