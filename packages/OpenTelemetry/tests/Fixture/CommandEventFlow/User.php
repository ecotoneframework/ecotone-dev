<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
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
