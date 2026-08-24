<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\ExceptionFlow;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;
use InvalidArgumentException;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class User
{
    #[Identifier]
    private string $userId;

    #[CommandHandler]
    public static function register(RegisterUser $command): self
    {
        throw new InvalidArgumentException('User already registered');
    }
}
