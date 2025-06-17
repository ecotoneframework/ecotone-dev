<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

use DateTimeImmutable;
use DateTimeInterface;

class DateUtils
{
    public static function getTimestampWithMillisecondsFor(DateTimeInterface $dateTime): int
    {
        return (int)round($dateTime->format('U.u') * 1000);
    }

    public static function getTimestampFor(DateTimeInterface $dateTime): int
    {
        return (int)$dateTime->format('U');
    }
}