<?php

declare(strict_types=1);

namespace Fixture\ExpressionLanguage;

use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

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
