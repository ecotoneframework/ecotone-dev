<?php

namespace Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate;

/**
 * Class CommandWithoutAggregateIdentifier
 * @package Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class CommandWithoutAggregateIdentifier
{
    /**
     * @var string|null
     */
    private $orderId;

    /**
     * CommandWithoutAggregateIdentifier constructor.
     * @param string $orderId
     */
    private function __construct(?string $orderId)
    {
        $this->orderId = $orderId;
    }

    public static function create(?string $orderId): self
    {
        return new self($orderId);
    }
}
