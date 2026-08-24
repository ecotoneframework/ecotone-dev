<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Header;
use Ecotone\Api\Identifier;
use Ecotone\Api\QueryHandler;
use InvalidArgumentException;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class User
{
    #[Identifier]
    private string $userId;

    #[CommandHandler]
    public static function register(RegisterUser $command, #[Header('throwException')] bool $throwException = false): self
    {
        if ($throwException) {
            throw new InvalidArgumentException('Registration failed.');
        }

        $user = new self();
        $user->userId = $command->userId;

        return $user;
    }

    #[QueryHandler('user.get')]
    public function isRegistered(): bool
    {
        return true;
    }
}
