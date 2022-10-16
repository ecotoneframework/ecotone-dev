<?php declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixture;

use Ecotone\Messaging\Attribute\Parameter\ConfigurationVariable;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Messaging\Gateway\Converter\Serializer;

class MoneyService
{
    private array $bank = [];

    public function __construct(private Serializer $serializer)
    {

    }

    #[CommandHandler]
    public function add(AddMoney $command, #[ConfigurationVariable] int $currentExchange): void
    {
        if (!isset($this->bank[$command->personId])) {
            $this->bank[$command->personId] = 0;
        }

        $this->bank[$command->personId] += $command->amount * $currentExchange;
    }

    #[QueryHandler("person.getMoney")]
    public function getMoney(int $personId): int
    {
        if (!isset($this->bank[$personId])) {
            return 0;
        }

        return $this->bank[$personId];
    }
}