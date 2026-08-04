<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

/**
 * Marks a Message Channel Builder whose Channel can act as the draining source of a Combined Message Channel relay,
 * fetching multiple messages per cycle and forwarding them in batch to the next channel in the chain.
 *
 * licence Enterprise
 */
interface BatchForwardingSourceChannel
{
}
