<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Ecotone\Messaging\Attribute\Converter;

final readonly class DateTimeToDayStringConverter
{
    #[Converter]
    public function to(\DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d');
    }
}