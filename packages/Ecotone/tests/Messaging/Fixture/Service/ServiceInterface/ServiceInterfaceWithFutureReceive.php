<?php

namespace Test\Ecotone\Messaging\Fixture\Service\ServiceInterface;

use Ecotone\Messaging\Future;

/**
 * Interface ServiceInterfaceWithFutureReceive
 * @package Test\Ecotone\Messaging\Fixture\Service\ServiceInterface
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface ServiceInterfaceWithFutureReceive
{
    /**
     * @return Future
     */
    public function someLongRunningWork(): Future;
}
