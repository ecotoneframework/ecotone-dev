<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

enum ProjectionInitializationMode: string
{
    case AUTO = 'auto';
    case SKIP = 'skip';
}
