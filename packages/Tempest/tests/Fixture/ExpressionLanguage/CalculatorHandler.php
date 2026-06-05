<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\ExpressionLanguage;

use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class CalculatorHandler
{
    private int $result = 0;
    private int $envResult = 0;

    #[CommandHandler('calculator.multiply')]
    public function multiply(#[Payload("parameter('ECOTONE_MULTIPLIER') * payload['value']")] int $calculatedValue): void
    {
        $this->result = $calculatedValue;
    }

    #[CommandHandler('calculator.multiplyWithEnv')]
    public function multiplyWithEnv(#[Payload("parameter('ECOTONE_ENV_MULTIPLIER') * payload['value']")] int $calculatedValue): void
    {
        $this->envResult = $calculatedValue;
    }

    #[QueryHandler('calculator.getResult')]
    public function getResult(): int
    {
        return $this->result;
    }

    #[QueryHandler('calculator.getEnvResult')]
    public function getEnvResult(): int
    {
        return $this->envResult;
    }
}
