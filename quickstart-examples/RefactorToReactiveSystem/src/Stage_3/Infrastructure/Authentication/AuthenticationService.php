<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Infrastructure\Authentication;

use Ecotone\Messaging\Attribute\Interceptor\Presend;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ramsey\Uuid\UuidInterface;

/**
 * This is just an example.
 * Normally you would fetch it from Session or Access Token.
 */
final class AuthenticationService
{
    const EXECUTOR_ID_HEADER = "executorId";

    public function __construct(private UuidInterface $userId)
    {

    }

    public function getCurrentUserId(): UuidInterface
    {
        return $this->userId;
    }

    #[Presend(pointcut: CommandHandler::class, changeHeaders: true)]
    public function addExecutorToMetadata(): array
    {
        return [self::EXECUTOR_ID_HEADER => $this->getCurrentUserId()->toString()];
    }
}