<?php

namespace Incorrect\TestingNamespace;

use Ecotone\Api\ServiceContext;

/**
 * licence Apache-2.0
 */
class CorrectNamespace
{
    #[ServiceContext]
    public function someExtension(): array
    {
        return [];
    }
}
