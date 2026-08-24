<?php

namespace Test\Ecotone\Modelling\Fixture\AggregateIdFromMethod;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\IdentifierMethod;
use Ecotone\Api\QueryHandler;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class User
{
    private string $internalId;
    private string $name;

    #[CommandHandler]
    public static function create(CreateUser $command): self
    {
        $self = new self();
        $self->internalId = $command->id;
        $self->name = $command->name;

        return $self;
    }

    #[IdentifierMethod('id')]
    public function getIdentifier()
    {
        return $this->internalId;
    }

    #[QueryHandler('user.getName')]
    public function getName(): string
    {
        return $this->name;
    }
}
