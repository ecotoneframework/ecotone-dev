<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\Shopping;

/**
 * Class OrderRequest
 * @package Test\Ecotone\Messaging\Fixture\Behat\Shopping
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class ReserveRequest
{
    /**
     * @var string
     */
    private $name;

    /**
     * OrderRequest constructor.
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }
}
