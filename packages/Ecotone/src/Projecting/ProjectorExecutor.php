<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Modelling\Event;

interface ProjectorExecutor
{
    public function project(Event $event): void;
}