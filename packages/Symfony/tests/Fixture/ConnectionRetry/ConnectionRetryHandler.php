<?php

declare(strict_types=1);

namespace Fixture\ConnectionRetry;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class ConnectionRetryHandler
{
    private array $handled = [];

    #[Asynchronous('connection_lost_four_times')]
    #[CommandHandler('connection_retry.four_times', 'connection_lost_four_times_endpoint')]
    public function afterFourLostConnections(string $payload): void
    {
        $this->handled[] = $payload;
    }

    #[Asynchronous('connection_lost_five_times')]
    #[CommandHandler('connection_retry.five_times', 'connection_lost_five_times_endpoint')]
    public function afterFiveLostConnections(string $payload): void
    {
        $this->handled[] = $payload;
    }

    #[QueryHandler('connection_retry.handled')]
    public function handled(): array
    {
        return $this->handled;
    }
}
