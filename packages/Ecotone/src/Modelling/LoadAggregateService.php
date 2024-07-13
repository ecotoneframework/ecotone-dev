<?php

declare(strict_types=1);

namespace Ecotone\Modelling;

use Ecotone\Messaging\Message;

/**
 * Class LoadAggregateService
 * @package Ecotone\Modelling
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 * @internal
 */
interface LoadAggregateService
{
    public function load(Message $message): ?Message;
}
