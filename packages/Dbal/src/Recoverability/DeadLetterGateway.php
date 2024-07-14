<?php

namespace Ecotone\Dbal\Recoverability;

use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
interface DeadLetterGateway
{
    /**
     * @return ErrorContext[]
     */
    public function list(int $limit, int $offset): array;

    public function show(string $messageId): Message;

    public function count(): int;

    public function reply(string|array $messageId): void;

    public function replyAll(): void;

    public function delete(string|array $messageId): void;

    public function deleteAll(): void;
}
