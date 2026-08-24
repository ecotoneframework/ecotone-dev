<?php

namespace Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\IdentifierMethod;
use Ecotone\Modelling\WithEvents;
use Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate\NoIdDefinedAfterCallingFactory\CreateNoIdDefinedAggregate;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class PublicIdentifierGetMethodForEventSourcedAggregate
{
    use WithEvents;

    private $internalId;

    #[CommandHandler]
    public static function create(CreateNoIdDefinedAggregate $command): self
    {
        $self = new self();
        $self->internalId = $command->id;

        return $self;
    }

    #[IdentifierMethod('id')]
    public function getId()
    {
        return $this->internalId;
    }
}
