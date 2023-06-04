<?php

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Support\Assert;
use Prooph\EventStore\Pdo\Projection\GapDetection;
use Prooph\EventStore\Pdo\Projection\PdoEventStoreReadModelProjector;

final class ProjectionSetupConfiguration
{
    /** @var ProjectionEventHandlerConfiguration[] */
    private array $projectionEventHandlerConfigurations = [];
    /** @var array http://docs.getprooph.org/event-store/projections.html#Options https://github.com/prooph/pdo-event-store/pull/221/files */
    private array $projectionOptions;
    private bool $keepStateBetweenEvents = true;

    private function __construct(
        private string $projectionName,
        private ProjectionLifeCycleConfiguration $projectionLifeCycleConfiguration,
        private string $eventStoreReferenceName,
        private ProjectionStreamSource $projectionStreamSource,
        private ?string $asynchronousChannelName
    ) {
        $this->projectionOptions = [
            PdoEventStoreReadModelProjector::OPTION_GAP_DETECTION => new GapDetection(),
        ];
    }

    public static function create(string $projectionName, ProjectionLifeCycleConfiguration $projectionLifeCycleConfiguration, string $eventStoreReferenceName, ProjectionStreamSource $projectionStreamSource, ?string $asynchronousChannelName): static
    {
        return new static($projectionName, $projectionLifeCycleConfiguration, $eventStoreReferenceName, $projectionStreamSource, $asynchronousChannelName);
    }

    public function withKeepingStateBetweenEvents(bool $keepState): static
    {
        $this->keepStateBetweenEvents = $keepState;

        return $this;
    }

    public function isKeepingStateBetweenEvents(): bool
    {
        return $this->keepStateBetweenEvents;
    }

    public function withProjectionEventHandler(string $eventBusRoutingKey, string $className, string $methodName, string $eventHandlerInputChannel, string $asynchronousEventHandlerRequestChannel): static
    {
        Assert::keyNotExists($this->projectionEventHandlerConfigurations, $eventBusRoutingKey, "Projection {$this->projectionName} has incorrect configuration. Can't register event handler twice for the same event {$eventBusRoutingKey}");

        $this->projectionEventHandlerConfigurations[$eventBusRoutingKey] = new ProjectionEventHandlerConfiguration($className, $methodName, $eventBusRoutingKey, $eventHandlerInputChannel, $asynchronousEventHandlerRequestChannel);

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

    /**
     * If projection is running in asynchronous mode, this channel allows to send
     * a message to trigger it to perform specific action
     */
    public function getTriggeringChannelName(): string
    {
        if ($this->getProjectionEventHandlerConfigurations()) {
            /** @var ProjectionEventHandlerConfiguration $first */
            $first = reset($this->projectionEventHandlerConfigurations);

            return $first->getEventHandlerAsynchronousInputChannel();
        }

        return NullableMessageChannel::CHANNEL_NAME;
    }

    public function getInitializationChannelName(): string
    {
        return $this->projectionLifeCycleConfiguration->getInitializationRequestChannel() ?? NullableMessageChannel::CHANNEL_NAME;
    }
}
