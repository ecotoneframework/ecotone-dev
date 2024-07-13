<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler;

use Ecotone\Messaging\Message;

/**
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface ParameterConverter
{
    /**
     * @return mixed
     */
    public function getArgumentFrom(Message $message);
}
