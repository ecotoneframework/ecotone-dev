<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Attribute;

use Attribute;

/**
 * Configuration for global scope projections.
 * This attribute allows configuring automatic initialization behavior.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class GlobalScopeConfiguration
{
    public function __construct(
        public readonly bool $automaticInitialization = true,
    ) {
    }
}

