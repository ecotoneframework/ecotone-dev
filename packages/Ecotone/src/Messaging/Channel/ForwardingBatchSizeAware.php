<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

/**
 * licence Apache-2.0
 */
interface ForwardingBatchSizeAware
{
    public function getMaxForwardingBatchSize(): int;
}
