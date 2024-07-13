<?php

namespace Ecotone\Messaging;

/**
 * Class Future
 * @package Ecotone\Messaging\Handler\Gateway
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface Future
{
    /**
     * Resolves future by retrieving response
     *
     * @return mixed
     */
    public function resolve();
}
