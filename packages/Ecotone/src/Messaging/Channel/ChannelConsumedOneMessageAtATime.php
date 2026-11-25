<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

/**
 * licence Apache-2.0
 */
interface ChannelConsumedOneMessageAtATime
{
    /**
     * @return array{reason: string, wayToHandleMoreAtOnce: string}
     */
    public function whyItsConsumerHandlesOneMessageAtATime(): array;
}
