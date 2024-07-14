<?php

namespace Ecotone\Enqueue;

use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;

/**
 * licence Apache-2.0
 */
interface ReconnectableConnectionFactory extends ConnectionFactory
{
    public function isDisconnected(?Context $context): bool;

    public function reconnect(): void;

    public function getConnectionInstanceId(): string;

    public function getWrappedConnectionFactory(): ConnectionFactory;
}
