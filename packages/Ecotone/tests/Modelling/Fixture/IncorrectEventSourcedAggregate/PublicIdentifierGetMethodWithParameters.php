<?php

namespace Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\IdentifierMethod;
use Ecotone\Modelling\Attribute\CommandHandler;
use stdClass;
use Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate\NoIdDefinedAfterCallingFactory\CreateNoIdDefinedAggregate;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class PublicIdentifierGetMethodWithParameters
{
    private $internalId;

    #[CommandHandler]
    public static function create(CreateNoIdDefinedAggregate $command): array
    {
        return [new stdClass()];
    }

    #[IdentifierMethod('id')]
    public function getId(stdClass $param)
    {
        return $this->internalId;
    }
}
