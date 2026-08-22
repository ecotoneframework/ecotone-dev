<?php

namespace Ecotone\Modelling;

use Ecotone\Modelling\Attribute\Version;

/**
 * licence Apache-2.0
 */
trait WithAggregateVersioning
{
    #[Version]
    private int $version = 0;
}
