<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate;

use Ecotone\Messaging\Attribute\Converter;

/**
 * licence Apache-2.0
 */
final class BalanceCreatedConverter
{
    #[Converter]
    public function from(BalanceCreated $event): array
    {
        return ['balanceId' => UuidV4Converter::convertToString($event->balanceId)];
    }

    #[Converter]
    public function to(array $event): BalanceCreated
    {
        return new BalanceCreated(UuidV4Converter::convertFromString($event['balanceId']));
    }
}
