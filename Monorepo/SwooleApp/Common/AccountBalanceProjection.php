<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;

#[Projection('swoole_account_balance')]
#[FromAggregateStream(Account::class)]
final class AccountBalanceProjection
{
    public function __construct(private Database $database)
    {
    }

    #[EventHandler]
    public function onOpened(AccountOpened $event): void
    {
        $this->database->connection()->insert('swoole_balance', ['account_id' => $event->accountId, 'balance' => 0]);
    }

    #[EventHandler]
    public function onDeposited(MoneyDeposited $event): void
    {
        $this->database->connection()->executeStatement('UPDATE swoole_balance SET balance = balance + ? WHERE account_id = ?', [$event->amount, $event->accountId]);
    }

    #[QueryHandler('account.balance')]
    public function balance(string $accountId): ?int
    {
        $balance = $this->database->connection()->fetchOne('SELECT balance FROM swoole_balance WHERE account_id = ?', [$accountId]);

        return $balance === false ? null : (int) $balance;
    }
}
