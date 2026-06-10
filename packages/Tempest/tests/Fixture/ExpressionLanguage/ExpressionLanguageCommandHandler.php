<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\ExpressionLanguage;

use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class ExpressionLanguageCommandHandler
{
    private int $amount = 0;

    #[CommandHandler('setAmount')]
    public function execute(#[Payload("payload['amount']")] int $amount): void
    {
        $this->amount = $amount;
    }

    #[QueryHandler('getAmount')]
    public function getAmount(): int
    {
        return $this->amount;
    }
}
