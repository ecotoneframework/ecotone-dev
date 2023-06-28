<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;

#[Aggregate]
final class User
{
    #[AggregateIdentifier]
    private string $userId;

    #[Asynchronous("async_channel")]
    #[CommandHandler(endpointId: "user.register")]
    public static function register(RegisterUser $command): self
    {
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
