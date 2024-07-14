<?php

namespace Test\Ecotone\JMSConverter\Fixture\Configuration\Status;

/**
 * licence Apache-2.0
 */
class Person
{
    private Status $status;

    /**
     * Person constructor.
     * @param Status $status
     */
    public function __construct(Status $status)
    {
        $this->status = $status;
    }
}
