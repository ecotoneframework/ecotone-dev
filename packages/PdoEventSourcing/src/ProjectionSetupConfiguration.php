<?php

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Support\Assert;
use Prooph\EventStore\Pdo\Projection\GapDetection;
use Prooph\EventStore\Pdo\Projection\PdoEventStoreReadModelProjector;

final class ProjectionSetupConfiguration implements DefinedObject
{
    /**
     * @param ProjectionEventHandlerConfiguration[] $projectionEventHandlerConfigurations
     * @param array $projectionOptions http://docs.getprooph.org/event-store/projections.html#Options https://github.com/prooph/pdo-event-store/pull/221/files
     */
    public function __construct(
        private string $projectionName,
        private ProjectionLifeCycleConfiguration $projectionLifeCycleConfiguration,
        private string $eventStoreReferenceName,
        private ProjectionStreamSource $projectionStreamSource,
        private ?string $asynchronousChannelName,
        private bool $isPolling = false,
        private array $projectionEventHandlerConfigurations = [],
        private bool $keepStateBetweenEvents = true,
        private array $projectionOptions = [],
    ) {
    }

    public static function create(string $projectionName, ProjectionLifeCycleConfiguration $projectionLifeCycleConfiguration, string $eventStoreReferenceName, ProjectionStreamSource $projectionStreamSource, ?string $asynchronousChannelName): static
    {
        return new self($projectionName, $projectionLifeCycleConfiguration, $eventStoreReferenceName, $projectionStreamSource, $asynchronousChannelName, projectionOptions: [PdoEventStoreReadModelProjector::OPTION_GAP_DETECTION => new GapDetection()]);
    }

    public function getDefinition(): Definition
    {
        $projectionOptions = [];
        foreach ($this->projectionOptions as $key => $value) {
            if ($value instanceof GapDetection) {
                $projectionOptions[$key] = new Definition(GapDetection::class);
            } else {
                $projectionOptions[$key] = $value;
            }
        }
        return new Definition(
            ProjectionSetupConfiguration::class,
            [
                $this->projectionName,
                $this->projectionLifeCycleConfiguration->getDefinition(),
                $this->eventStoreReferenceName,
                $this->projectionStreamSource->getDefinition(),
                $this->asynchronousChannelName,
                $this->isPolling,
                $this->projectionEventHandlerConfigurations,
                $this->keepStateBetweenEvents,
                $projectionOptions,
            ]
        );
    }

    public function withKeepingStateBetweenEvents(bool $keepState): static
    {
        $this->keepStateBetweenEvents = $keepState;

        return $this;
    }

    public function withPolling(bool $isPolling): self
    {
        $this->isPolling = $isPolling;

        return $this;
    }

    public function isKeepingStateBetweenEvents(): bool
    {
        return $this->keepStateBetweenEvents;
    }

    public function withProjectionEventHandler(string $eventBusRoutingKey, string $className, string $methodName, string $eventHandlerInputChannel): static
    {
        Assert::keyNotExists($this->projectionEventHandlerConfigurations, $eventBusRoutingKey, "Projection {$this->projectionName} has incorrect configuration. Can't register event handler twice for the same event {$eventBusRoutingKey}");

        $this->projectionEventHandlerConfigurations[$eventBusRoutingKey] = new ProjectionEventHandlerConfiguration($className, $methodName, $eventBusRoutingKey, $eventHandlerInputChannel);

        return $this;
    }

    public function getEventStoreReferenceName(): string
    {
        return $this->eventStoreReferenceName;
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

    /**
     * @return ProjectionEventHandlerConfiguration[]
     */
    public function getProjectionEventHandlerConfigurations(): array
    {
        return $this->projectionEventHandlerConfigurations;
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
}
