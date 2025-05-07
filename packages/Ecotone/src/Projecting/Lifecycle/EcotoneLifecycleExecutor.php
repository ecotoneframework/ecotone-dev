<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Lifecycle;

use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Projecting\ProjectionStateStorage;

class EcotoneLifecycleExecutor implements LifecycleExecutor
{
    /**
     * @param array<string, string> $projectionInitChannels key is projection name, value is channel name
     * @param array<string, string> $projectionResetChannels key is projection name, value is channel name
     * @param array<string, string> $projectionDeleteChannels key is projection name, value is channel name
     */
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private array $projectionInitChannels,
        private array $projectionResetChannels,
        private array $projectionDeleteChannels,
    ) {}

    public function init(string $projectionName): void
    {
        if (isset($this->projectionInitChannels[$projectionName])) {
            $this->messagingEntrypoint->send([], $this->projectionInitChannels[$projectionName]);
        }
    }

    public function reset(string $projectionName): void
    {
        if (isset($this->projectionResetChannels[$projectionName])) {
            $this->messagingEntrypoint->send([], $this->projectionResetChannels[$projectionName]);
        }
    }

    public function delete(string $projectionName): void
    {
        if (isset($this->projectionDeleteChannels[$projectionName])) {
            $this->messagingEntrypoint->send([], $this->projectionDeleteChannels[$projectionName]);
        }
    }
}