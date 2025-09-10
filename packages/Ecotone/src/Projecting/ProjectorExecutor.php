<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Modelling\Event;

interface ProjectorExecutor
{
    /**
     * @param mixed|null $userState
     * @return mixed the new user state
     */
    public function project(Event $event, mixed $userState = null): mixed;
}