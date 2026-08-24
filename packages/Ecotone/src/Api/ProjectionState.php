<?php

namespace Ecotone\Api;

use Attribute;
use Ecotone\Projecting\ProjectingHeaders;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Apache-2.0
 */
final class ProjectionState extends Header
{
    public function __construct()
    {
        parent::__construct(ProjectingHeaders::PROJECTION_STATE);
    }
}
