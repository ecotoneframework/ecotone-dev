<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\MetadataPropagator;

/**
 * licence Apache-2.0
 */
final class PropagatedHeaders
{
    /** @var array<int, array<string, mixed>> */
    public array $headersOfHandledMessages = [];
    /** @var MessageCausation[] */
    public array $causationChain = [];
    public bool $isPollingConsumer = false;
}
