<?php

namespace Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate;

use Ecotone\Modelling\Attribute\TargetAggregateIdentifier;

/**
 * Class FinishOrderCommand
 * @package Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class FinishOrderCommand
{
    #[TargetAggregateIdentifier]
    private $orderId;

    /**
     * FinishOrderCommand constructor.
     * @param string $orderId
     */
    private function __construct(string $orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * @param string $orderId
     * @return FinishOrderCommand
     */
    public static function create(string $orderId): self
    {
        return new self($orderId);
    }
}
