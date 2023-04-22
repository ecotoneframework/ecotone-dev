<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Support\Assert;

final class CombinedMessageChannel
{
    private function __construct(private string $referenceName, private array $combinedChannels)
    {
        Assert::notNull($referenceName, 'Reference name can not be null');
        Assert::notNullAndEmpty($this->combinedChannels, 'Combined channels can not be empty');
    }

    public static function create(string $referenceName, array $combinedChannels): self
    {
        return new self($referenceName, $combinedChannels);
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    public function getCombinedChannels(): array
    {
        return $this->combinedChannels;
    }
}