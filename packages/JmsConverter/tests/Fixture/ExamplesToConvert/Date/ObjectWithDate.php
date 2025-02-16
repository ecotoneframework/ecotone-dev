<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Date;

final class ObjectWithDate
{
    public function __construct(public \DateTimeInterface $date)
    {

    }
}