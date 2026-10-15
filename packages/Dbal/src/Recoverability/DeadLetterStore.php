<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;

/**
 * licence Apache-2.0
 */
interface DeadLetterStore
{
    public function store(Message $message): void;

    /**
     * @return ErrorContext[]
     */
    public function list(int $limit, int $offset): array;

    public function find(string $messageId): ?Message;

    public function count(): int;

    public function delete(string $messageId): void;

    public function deleteAll(): void;
}
