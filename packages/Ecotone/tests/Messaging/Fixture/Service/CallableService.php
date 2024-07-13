<?php

namespace Test\Ecotone\Messaging\Fixture\Service;

/**
 * Interface CallableService
 * @package Test\Ecotone\Messaging\Fixture\Service
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface CallableService
{
    /**
     * @return bool
     */
    public function wasCalled(): bool;
}
