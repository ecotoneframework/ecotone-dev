<?php

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Apache-2.0
 */
final class ProjectionSetupConfiguration implements DefinedObject
{
    /**
     * @param array $projectionOptions http://docs.getprooph.org/event-store/projections.html#Options https://github.com/prooph/pdo-event-store/pull/221/files
     */
    public function __construct(
        private string $projectionName,
        private ProjectionLifeCycleConfiguration $projectionLifeCycleConfiguration,
        private string $eventStoreReferenceName,
        private ProjectionStreamSource $projectionStreamSource,
        private ?string $asynchronousChannelName,
        private bool $isPolling = false,
        private array $projectionOptions = [],
    ) {
    }

    public static function create(string $projectionName, ProjectionLifeCycleConfiguration $projectionLifeCycleConfiguration, string $eventStoreReferenceName, ProjectionStreamSource $projectionStreamSource, ?string $asynchronousChannelName): static
    {
        return new self($projectionName, $projectionLifeCycleConfiguration, $eventStoreReferenceName, $projectionStreamSource, $asynchronousChannelName);
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            ProjectionSetupConfiguration::class,
            [
                $this->projectionName,
                $this->projectionLifeCycleConfiguration->getDefinition(),
                $this->eventStoreReferenceName,
                $this->projectionStreamSource->getDefinition(),
                $this->asynchronousChannelName,
                $this->isPolling,
                $this->projectionOptions,
            ]
        );
    }

    public function withPolling(bool $isPolling): self
    {
        $this->isPolling = $isPolling;

        return $this;
    }

    public function withOptions(array $options): static
    {
        $this->projectionOptions = $options;

        return $this;
    }

    public function getProjectionName(): string
    {
        return $this->projectionName;
    }

    public function getProjectionStreamSource(): ProjectionStreamSource
    {
        return $this->projectionStreamSource;
    }

    public function getProjectionLifeCycleConfiguration(): ProjectionLifeCycleConfiguration
    {
        return $this->projectionLifeCycleConfiguration;
    }

    public function getProjectionOptions(): array
    {
        return $this->projectionOptions;
    }

    public function getAsynchronousChannelName(): ?string
    {
        return $this->asynchronousChannelName;
    }

    public function isAsynchronous(): bool
    {
        return $this->asynchronousChannelName !== null;
    }

    public function getProjectionInputChannel(): string
    {
        return 'projection_handler_' . $this->projectionName;
    }

    public function getProjectionEndpointId(): string
    {
        return $this->getProjectionInputChannel() . '_endpoint';
    }

    /**
     * If projection in non polling, we need to trigger it in order to make given action like rebuild, initialize, delete
     */
    public function getTriggeringChannelName(): string
    {
        if ($this->isPolling) {
            return NullableMessageChannel::CHANNEL_NAME;
        }

        return $this->getProjectionInputChannel();
    }

    public function getInitializationChannelName(): string
    {
        return $this->projectionLifeCycleConfiguration->getInitializationRequestChannel() ?? NullableMessageChannel::CHANNEL_NAME;
    }

    public function getActionRouterChannel(): string
    {
        return 'projection_action_router_' . $this->projectionName;
    }
}
