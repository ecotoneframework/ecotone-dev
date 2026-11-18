<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Throwable;

final class Probe
{
    private static int $handledByThisWorker = 0;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private Database $database,
    ) {
    }

    public function handle(string $action, array $input): array
    {
        self::$handledByThisWorker++;

        try {
            return ['ok' => true] + $this->{'action' . ucfirst($action)}($input);
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception::class . ': ' . $exception->getMessage(), 'worker_pid' => getmypid()];
        }
    }

    private function actionSetup(array $input): array
    {
        $this->database->createTables();

        return [];
    }

    private function actionState(array $input): array
    {
        return [
            'worker_pid' => getmypid(),
            'coroutine_id' => Runtime::coroutineId(),
            'handled_by_this_worker' => self::$handledByThisWorker,
            'session_id' => $this->database->sessionId(),
            'memory_kb' => (int) (memory_get_usage() / 1024),
            'command_bus_object' => spl_object_id($this->commandBus),
            'in_transaction' => $this->database->connection()->isTransactionActive(),
        ];
    }

    private function actionRecord(array $input): array
    {
        $this->commandBus->sendWithRouting('ledger.record', $input, metadata: $input['metadata'] ?? []);

        return ['worker_pid' => getmypid(), 'coroutine_id' => Runtime::coroutineId()];
    }

    private function actionLedger(array $input): array
    {
        return $this->queryBus->sendWithRouting('ledger.get', $input['id']);
    }

    private function actionOpen(array $input): array
    {
        $this->commandBus->send(new OpenAccount($input['accountId']));

        return [];
    }

    private function actionDeposit(array $input): array
    {
        $this->commandBus->send(new Deposit($input['accountId'], (int) $input['amount']));

        return [];
    }

    private function actionBalance(array $input): array
    {
        return ['balance' => $this->queryBus->sendWithRouting('account.balance', $input['accountId'])];
    }

    private function actionKill(array $input): array
    {
        $sessionId = $this->database->sessionId();
        $administrator = DriverManager::getConnection((new DsnParser(['pgsql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_DSN')));
        $administrator->executeStatement(Database::isMysql($administrator) ? 'KILL ' . $sessionId : 'SELECT pg_terminate_backend(' . $sessionId . ')');
        $administrator->close();

        return ['killed_session_id' => $sessionId, 'worker_pid' => getmypid()];
    }

    private function actionSleep(array $input): array
    {
        $this->database->sleep((float) $input['seconds']);

        return ['worker_pid' => getmypid(), 'coroutine_id' => Runtime::coroutineId(), 'session_id' => $this->database->sessionId()];
    }
}
