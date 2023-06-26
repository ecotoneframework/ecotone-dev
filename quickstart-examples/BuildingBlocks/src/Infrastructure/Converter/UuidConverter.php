<?php

declare(strict_types=1);

namespace App\Infrastructure\Converter;

use Ecotone\Messaging\Attribute\Converter;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * @link https://docs.ecotone.tech/messaging/conversion/conversion
 */
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