<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Api;

use Attribute;
use Ecotone\Projecting\ProjectingHeaders;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ProjectionName extends Header
{
    public function __construct()
    {
    }

    public function getHeaderName(): string
    {
        return ProjectingHeaders::PROJECTION_NAME;
    }
}
