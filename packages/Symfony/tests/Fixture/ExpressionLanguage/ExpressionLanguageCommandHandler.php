<?php

declare(strict_types=1);

namespace Fixture\ExpressionLanguage;

use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class ExpressionLanguageCommandHandler
{
    private int $amount;

    #[CommandHandler('setAmount')]
    public function execute(#[Payload("payload['amount']")] int $amount)
    {
        $this->amount = $amount;
    }

    #[QueryHandler('getAmount')]
    public function getAmount(): int
    {
        return $this->amount;
    }
}
