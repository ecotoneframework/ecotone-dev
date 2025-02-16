<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Date;

use DateTimeImmutable;
use DateTimeInterface;
use Ecotone\Messaging\Attribute\Converter;

final class YearMonthDayDateConverter
{
    #[Converter]
    public function convert(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    #[Converter]
    public function reverse(string $date): DateTimeInterface
    {
        return new DateTimeImmutable($date);
    }
}
