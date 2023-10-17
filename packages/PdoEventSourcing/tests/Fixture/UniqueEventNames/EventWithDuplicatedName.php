<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\UniqueEventNames;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('event')]
final class EventWithDuplicatedName
{
}
