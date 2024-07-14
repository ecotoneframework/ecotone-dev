<?php

namespace Test\Ecotone\JMSConverter\Fixture\Configuration\Status;

/**
 * licence Apache-2.0
 */
class Status
{
    private string $type;

    /**
     * Status constructor.
     * @param string $type
     */
    public function __construct(string $type)
    {
        $this->type = $type;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }
}
