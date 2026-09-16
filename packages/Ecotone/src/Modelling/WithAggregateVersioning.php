<?php

namespace Ecotone\Modelling;

use Ecotone\Api\Version;

/**
 * licence Apache-2.0
 */
trait WithAggregateVersioning
{
    #[Version]
    private int $version = 0;

    public function getVersion(): int
    {
        return $this->version;
    }
}
