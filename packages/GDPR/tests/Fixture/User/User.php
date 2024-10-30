<?php

declare(strict_types=1);

namespace Test\Ecotone\GDPR\Fixture\User;

use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[AggregateType('user')]
#[Stream('user')]
final class User
{
    use WithAggregateVersioning;

    #[Identifier]
    public string $id;

    private string $email;

    #[CommandHandler]
    public static function register(Register $command): array
    {
        return [
            new UserRegistered($command->id, $command->email),
        ];
    }

    #[QueryHandler('user.email')]
    public function email(): string
    {
        return $this->email;
    }

    #[EventSourcingHandler]
    public function applyUserRegistered(UserRegistered $event): void
    {
        $this->id = $event->id;
        $this->email = $event->email;
    }
}
