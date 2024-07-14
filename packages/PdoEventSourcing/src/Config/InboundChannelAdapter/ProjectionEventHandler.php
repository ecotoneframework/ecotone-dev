<?php

namespace Ecotone\EventSourcing\Config\InboundChannelAdapter;

use Ecotone\EventSourcing\ChannelProjectionExecutor;
use Ecotone\EventSourcing\ProjectionSetupConfiguration;
use Ecotone\EventSourcing\ProjectionStatus;
use Ecotone\EventSourcing\Prooph\LazyProophProjectionManager;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Prooph\EventStore\StreamName;

/**
 * licence Apache-2.0
 */
class ProjectionEventHandler
{
    public const PROJECTION_STATE = 'projection.state';
    public const PROJECTION_IS_REBUILDING = 'projection.is_rebuilding';
    public const PROJECTION_NAME = 'projection.name';

    public function __construct(
        private LazyProophProjectionManager $lazyProophProjectionManager,
        private ProjectionSetupConfiguration $projectionSetupConfiguration,
        private ConversionService $conversionService
    ) {
    }

    public function execute(MessagingEntrypointWithHeadersPropagation $messagingEntrypoint): void
    {
        $status = ProjectionStatus::RUNNING();
        $projectHasRelatedStream = $this->lazyProophProjectionManager->hasInitializedProjectionWithName($this->projectionSetupConfiguration->getProjectionName());
        if ($projectHasRelatedStream) {
            $status = $this->lazyProophProjectionManager->getProjectionStatus($this->projectionSetupConfiguration->getProjectionName());
        } else {
            if ($this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getInitializationRequestChannel()) {
                $messagingEntrypoint->send([], $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getInitializationRequestChannel());
            }
        }

        $projectionExecutor = new ChannelProjectionExecutor($this->projectionSetupConfiguration, $this->conversionService, $messagingEntrypoint, $status);

        if ($status == ProjectionStatus::REBUILDING() && $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getRebuildRequestChannel()) {
            $messagingEntrypoint->send([], $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getRebuildRequestChannel());
        }

        if ($status == ProjectionStatus::DELETING() && $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getDeleteRequestChannel()) {
            $messagingEntrypoint->send([], $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getDeleteRequestChannel());
        }

        $this->lazyProophProjectionManager->run($this->projectionSetupConfiguration->getProjectionName(), $this->projectionSetupConfiguration->getProjectionStreamSource(), $projectionExecutor, array_keys($this->projectionSetupConfiguration->getProjectionEventHandlerConfigurations()), $this->projectionSetupConfiguration->getProjectionOptions());
        while (in_array($status->getStatus(), [ProjectionStatus::REBUILDING, ProjectionStatus::RUNNING], true)) {
            $status = $this->lazyProophProjectionManager->getProjectionStatus($this->projectionSetupConfiguration->getProjectionName());

            $this->lazyProophProjectionManager->run($this->projectionSetupConfiguration->getProjectionName(), $this->projectionSetupConfiguration->getProjectionStreamSource(), $projectionExecutor, array_keys($this->projectionSetupConfiguration->getProjectionEventHandlerConfigurations()), $this->projectionSetupConfiguration->getProjectionOptions());
        }

        if ($status == ProjectionStatus::DELETING() && $projectHasRelatedStream) {
            $projectionStreamName = new StreamName(LazyProophProjectionManager::getProjectionStreamName($this->projectionSetupConfiguration->getProjectionName()));
            if ($this->lazyProophProjectionManager->getLazyProophEventStore()->hasStream($projectionStreamName)) {
                $this->lazyProophProjectionManager->getLazyProophEventStore()->delete($projectionStreamName);
            }
        }
    }
}
