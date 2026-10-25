<?php

declare(strict_types=1);

namespace App\Ping;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface PongLog
{
    #[DbalWrite('INSERT INTO demo_pongs (ping_id, reply) VALUES (:pingId, :reply)')]
    public function record(string $pingId, string $reply): void;

    #[DbalQuery('SELECT reply FROM demo_pongs WHERE ping_id = :pingId', fetchMode: FetchMode::FIRST_COLUMN)]
    public function repliesTo(string $pingId): array;

    #[DbalQuery('SELECT reply FROM demo_pongs ORDER BY received_at', fetchMode: FetchMode::FIRST_COLUMN)]
    public function replies(): array;
}
