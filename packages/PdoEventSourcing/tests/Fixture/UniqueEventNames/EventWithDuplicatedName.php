<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\UniqueEventNames;

use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent('event')]
/**
 * licence Apache-2.0
 */
final class EventWithDuplicatedName
{
}
