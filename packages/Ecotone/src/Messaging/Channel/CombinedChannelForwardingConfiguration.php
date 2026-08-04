<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

/**
 * licence Enterprise
 */
final class CombinedChannelForwardingConfiguration
{
    public const DEFAULT_MAX_BATCH_SIZE = 100;

    /**
     * @param array<string, int> $maxForwardingBatchSizes indexed by relay source channel name
     */
    public function __construct(private array $maxForwardingBatchSizes = [])
    {
    }

    public function getMaxForwardingBatchSizeFor(string $channelName): int
    {
        return $this->maxForwardingBatchSizes[$channelName] ?? self::DEFAULT_MAX_BATCH_SIZE;
    }
}
