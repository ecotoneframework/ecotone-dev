<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Recoverability;

/**
 * licence Apache-2.0
 */
interface DeclaresReplayChannel
{
    public function getMessageChannelName(): string;

    public function replayChannelName(): string;
}
