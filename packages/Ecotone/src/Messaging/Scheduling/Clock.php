<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

/**
 * Interface Clock
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface Clock
{
    /**
     * @return integer Milliseconds since Epoch
     */
    public function unixTimeInMilliseconds(): int;
}
