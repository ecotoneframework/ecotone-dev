<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\ExceptionFlow;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use InvalidArgumentException;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class User
{
    #[AggregateIdentifier]
    private string $userId;

    #[CommandHandler]
    public static function register(RegisterUser $command): self
    {
        throw new InvalidArgumentException('User already registered');
    }
}
