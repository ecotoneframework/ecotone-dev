<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder;

use Ecotone\Api\Aggregate;
use Ecotone\Api\AggregateType;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;

#[AggregateType('something')]
#[Aggregate]
/**
 * licence Apache-2.0
 */
final class Something
{
    use WithAggregateVersioning;
    use WithEvents;

    public function __construct(#[Identifier] public int $int)
    {
        $this->recordThat(new SomethingWasCreatedPrivateEvent($int));
    }

    public function getAggregateVersion(): int
    {
        return $this->version;
    }
}
