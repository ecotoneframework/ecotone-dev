<?php

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
class ProjectionLifeCycleConfiguration implements DefinedObject
{
    private function __construct(
        private ?string $initializationRequestChannel,
        private ?string $resetRequestChannel,
        private ?string $deleteRequestChannel
    ) {
    }

    public static function create(
        ?string $initializationRequestChannel = null,
        ?string $resetRequestChannel = null,
        ?string $deleteRequestChannel = null
    ): static {
        return new self(
            $initializationRequestChannel,
            $resetRequestChannel,
            $deleteRequestChannel
        );
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            ProjectionLifeCycleConfiguration::class,
            [
                $this->initializationRequestChannel,
                $this->resetRequestChannel,
                $this->deleteRequestChannel,
            ],
            'create'
        );
    }

    public function withInitializationRequestChannel(string $initializationRequestChannel): static
    {
        $this->initializationRequestChannel = $initializationRequestChannel;

        return $this;
    }

    public function withDeleteRequestChannel(string $deleteRequestChannel): static
    {
        $this->deleteRequestChannel = $deleteRequestChannel;

        return $this;
    }

    public function withResetRequestChannel(string $resetRequestChannel): static
    {
        $this->resetRequestChannel = $resetRequestChannel;

        return $this;
    }

    public function getInitializationRequestChannel(): ?string
    {
        return $this->initializationRequestChannel;
    }

    public function getRebuildRequestChannel(): ?string
    {
        return $this->resetRequestChannel;
    }

    public function getDeleteRequestChannel(): ?string
    {
        return $this->deleteRequestChannel;
    }
}
