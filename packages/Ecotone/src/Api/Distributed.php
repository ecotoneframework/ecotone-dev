<?php

namespace Ecotone\Api;

use Attribute;

#[Attribute]
/**
 * licence Apache-2.0
 */
class Distributed
{
    private string $distributionReference;

    public function __construct(string $distributionReference = DistributedBus::class)
    {
        $this->distributionReference = $distributionReference;
    }

    public function getDistributionReference(): string
    {
        return $this->distributionReference;
    }
}
