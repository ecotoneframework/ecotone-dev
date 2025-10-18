<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

enum ProjectionStatus: string
{
    case ENABLED = 'enabled';
    case DISABLED = 'disabled';
    case UNINITIALIZED = 'uninitialized';
}
