<?php

namespace Ecotone\Messaging;

use Ecotone\Messaging\Endpoint\PollingMetadata;

/**
 * licence Apache-2.0
 */
interface MessagePoller
{
    /**
     * Receive with timeout using polling metadata
     * Tries to receive message till time out passes
     *
     * @param PollingMetadata $pollingMetadata Contains timeout and execution constraints
     */
    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message;
}
