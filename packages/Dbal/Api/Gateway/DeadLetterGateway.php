<?php

namespace Ecotone\Api\Dbal;

use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
interface DeadLetterGateway
{
    public function store(Message $message): void;

    /**
     * @return ErrorContext[]
     */
    public function list(int $limit, int $offset): array;

    public function show(string $messageId): Message;

    public function count(): int;

    public function replay(string|array $messageId): void;

    public function replayAll(): void;

    public function delete(string|array $messageId): void;

    public function deleteAll(): void;
}
