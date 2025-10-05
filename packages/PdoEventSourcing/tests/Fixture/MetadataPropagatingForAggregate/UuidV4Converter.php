<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate;

use Ecotone\Messaging\Attribute\Converter;
use Ramsey\Uuid\Rfc4122\UuidV4;
use Ramsey\Uuid\UuidFactory;

/**
 * licence Apache-2.0
 */
final class UuidV4Converter
{
    #[Converter]
    public static function convertFromString(string $uuid): UuidV4
    {
        $factory = new UuidFactory();

        /** @var UuidV4 $uuidV4 */
        $uuidV4 = $factory->fromString($uuid);

        return $uuidV4;
    }

    #[Converter]
    public static function convertToString(UuidV4 $uuid): string
    {
        return $uuid->toString();
    }
}
