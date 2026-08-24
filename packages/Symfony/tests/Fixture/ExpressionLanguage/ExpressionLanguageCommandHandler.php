<?php

declare(strict_types=1);

namespace Fixture\ExpressionLanguage;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\Payload;
use Ecotone\Api\QueryHandler;

/**
 * licence Apache-2.0
 */
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
