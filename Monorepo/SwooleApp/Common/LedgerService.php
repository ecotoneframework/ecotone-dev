<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\EventBus;
use RuntimeException;

final class LedgerService
{
    public function __construct(private Database $database)
    {
    }

    #[CommandHandler('ledger.record')]
    public function record(array $command, EventBus $eventBus, #[Header('tenant')] ?string $tenant = null): void
    {
        $this->database->connection()->insert('swoole_ledger', [
            'id' => $command['id'],
            'worker_pid' => getmypid(),
            'coroutine_id' => Runtime::coroutineId(),
            'session_id' => $this->database->sessionId(),
            'tenant' => $tenant,
        ]);

        if (($command['sleep'] ?? 0) > 0) {
            $this->database->sleep((float) $command['sleep']);
        }

        if (($command['pause'] ?? 0) > 0) {
            usleep((int) ($command['pause'] * 1_000_000));
        }

        $eventBus->publish(new LedgerRecorded($command['id']));

        if ($command['fail'] ?? false) {
            throw new RuntimeException('Requested failure of ' . $command['id']);
        }
    }

    #[EventHandler(endpointId: 'ledger.seen.sync')]
    public function seenSynchronously(LedgerRecorded $event, #[Header('tenant')] ?string $tenant = null): void
    {
        $this->seen($event, 'sync', $tenant);
    }

    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'ledger.seen.async')]
    public function seenAsynchronously(LedgerRecorded $event, #[Header('tenant')] ?string $tenant = null): void
    {
        $this->seen($event, 'async', $tenant);
    }

    #[QueryHandler('ledger.get')]
    public function get(string $id): array
    {
        $connection = $this->database->connection();

        return [
            'ledger' => $connection->fetchAllAssociative('SELECT * FROM swoole_ledger WHERE id = ?', [$id]),
            'seen' => $connection->fetchAllAssociative('SELECT * FROM swoole_seen WHERE id = ? ORDER BY handler', [$id]),
        ];
    }

    private function seen(LedgerRecorded $event, string $handler, ?string $tenant): void
    {
        $this->database->connection()->insert('swoole_seen', [
            'id' => $event->id,
            'handler' => $handler,
            'tenant' => $tenant,
            'worker_pid' => getmypid(),
        ]);
    }
}
