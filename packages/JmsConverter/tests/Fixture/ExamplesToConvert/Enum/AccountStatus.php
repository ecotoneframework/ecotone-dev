<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Enum;

enum AccountStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case BLOCKED = 'blocked';
}
