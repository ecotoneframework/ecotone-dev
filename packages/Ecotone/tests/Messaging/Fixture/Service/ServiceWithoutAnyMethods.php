<?php

namespace Test\Ecotone\Messaging\Fixture\Service;

/**
 * Class ServiceWithoutAnyMethods
 * @package Test\Ecotone\Messaging\Fixture\Service
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class ServiceWithoutAnyMethods
{
    public static function create(): self
    {
        return new self();
    }
}
