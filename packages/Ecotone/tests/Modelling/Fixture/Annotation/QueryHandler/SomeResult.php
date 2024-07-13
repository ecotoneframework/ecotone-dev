<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler;

use Ecotone\Modelling\Attribute\TargetAggregateIdentifier;

/**
 * Class SomeResult
 * @package Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class SomeResult
{
    #[TargetAggregateIdentifier]
    private $aggregateId;
}
