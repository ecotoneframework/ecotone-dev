<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\ExtensionObject;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class PartitionDeadLetterConfiguration
{
    /**
     * @param list<string> $messageChannels
     * @param list<class-string> $aggregates
     */
    private function __construct(
        public readonly array $messageChannels,
        public readonly array $aggregates,
    ) {
    }

    public static function create(): self
    {
        return new self([], []);
    }

    /**
     * @param list<string> $messageChannels
     */
    public function withMessageChannels(array $messageChannels): self
    {
        return new self(...[...get_object_vars($this), 'messageChannels' => array_values($messageChannels)]);
    }

    /**
     * @param list<class-string> $aggregates
     */
    public function withAggregates(array $aggregates): self
    {
        return new self(...[...get_object_vars($this), 'aggregates' => array_values($aggregates)]);
    }
}
