<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\ExpressionLanguage;

use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class CalculatorService
{
    private int $result = 0;
    private int $envResult = 0;

    #[CommandHandler('calculator.multiply')]
    public function multiply(#[Payload("parameter('app.multiplier') * payload['value']")] int $calculatedValue): void
    {
        $this->result = $calculatedValue;
    }

    #[CommandHandler('calculator.multiplyWithEnv')]
    public function multiplyWithEnv(#[Payload("parameter('app.env_multiplier') * payload['value']")] int $calculatedValue): void
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
