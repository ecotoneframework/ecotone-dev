<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Attribute;

use Attribute;

/**
 * This attribute allows configuring automatic initialization behavior.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ProjectionInitializationConfig
{
    public function __construct(
        public readonly bool $automaticInitialization = true,
    ) {
    }
}

