<?php

namespace Ecotone\EventSourcing\Config\InboundChannelAdapter;

use Ecotone\EventSourcing\ProjectionSetupConfiguration;
use Ecotone\EventSourcing\ProjectionStatus;
use Ecotone\EventSourcing\Prooph\LazyProophProjectionManager;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Projecting\ProjectingHeaders;
use Prooph\EventStore\StreamName;

/**
 * licence Apache-2.0
 */
class ProjectionEventHandler
{
    public const PROJECTION_STATE = ProjectingHeaders::PROJECTION_STATE;
    public const PROJECTION_EVENT_NAME = ProjectingHeaders::PROJECTION_EVENT_NAME;
    public const PROJECTION_IS_REBUILDING = ProjectingHeaders::PROJECTION_IS_REBUILDING;
    public const PROJECTION_NAME = ProjectingHeaders::PROJECTION_NAME;

    public function __construct(
        private LazyProophProjectionManager $lazyProophProjectionManager,
        private ProjectionSetupConfiguration $projectionSetupConfiguration,
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

        if ($status == ProjectionStatus::REBUILDING() && $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getRebuildRequestChannel()) {
            $messagingEntrypoint->send([], $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getRebuildRequestChannel());
        }

        if ($status == ProjectionStatus::DELETING() && $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getDeleteRequestChannel()) {
            $messagingEntrypoint->send([], $this->projectionSetupConfiguration->getProjectionLifeCycleConfiguration()->getDeleteRequestChannel());
        }

        $this->lazyProophProjectionManager->run($this->projectionSetupConfiguration->getProjectionName(), $this->projectionSetupConfiguration->getProjectionStreamSource(), $this->projectionSetupConfiguration->getProjectionOptions(), $status);
        while (in_array($status->getStatus(), [ProjectionStatus::REBUILDING, ProjectionStatus::RUNNING], true)) {
            $status = $this->lazyProophProjectionManager->getProjectionStatus($this->projectionSetupConfiguration->getProjectionName());

            $this->lazyProophProjectionManager->run($this->projectionSetupConfiguration->getProjectionName(), $this->projectionSetupConfiguration->getProjectionStreamSource(), $this->projectionSetupConfiguration->getProjectionOptions(), $status);
        }

        if ($status == ProjectionStatus::DELETING() && $projectHasRelatedStream) {
            $projectionStreamName = new StreamName(LazyProophProjectionManager::getProjectionStreamName($this->projectionSetupConfiguration->getProjectionName()));
            if ($this->lazyProophProjectionManager->getLazyProophEventStore()->hasStream($projectionStreamName)) {
                $this->lazyProophProjectionManager->getLazyProophEventStore()->delete($projectionStreamName);
            }
        }
    }
}
