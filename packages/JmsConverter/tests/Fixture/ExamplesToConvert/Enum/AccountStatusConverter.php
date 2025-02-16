<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Enum;

use Ecotone\Messaging\Attribute\Converter;

final class AccountStatusConverter
{
    #[Converter]
    public function from(AccountStatus $status): array
    {
        return [
            'value' => $status->value,
        ];
    }

    #[Converter]
    public function to(array $status): AccountStatus
    {
        return AccountStatus::from($status['value']);
    }
}
