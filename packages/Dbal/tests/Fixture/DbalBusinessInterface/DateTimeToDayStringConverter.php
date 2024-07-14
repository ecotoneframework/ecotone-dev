<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use DateTimeInterface;
use Ecotone\Messaging\Attribute\Converter;

/**
 * licence Apache-2.0
 */
final class DateTimeToDayStringConverter
{
    #[Converter]
    public function to(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d');
    }
}
