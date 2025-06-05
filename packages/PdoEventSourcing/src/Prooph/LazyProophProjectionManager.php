<?php

namespace Ecotone\EventSourcing\Prooph;

use Ecotone\EventSourcing\Config\InboundChannelAdapter\ProjectionEventHandler;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\ProjectionSetupConfiguration;
use Ecotone\EventSourcing\ProjectionStreamSource;
use Ecotone\EventSourcing\Prooph\Metadata\MetadataMatcher;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessageHeaders;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\Exception\ProjectionNotFound;
use Prooph\EventStore\Exception\RuntimeException;
use Prooph\EventStore\Pdo\Projection\MariaDbProjectionManager;
use Prooph\EventStore\Pdo\Projection\MySqlProjectionManager;
use Prooph\EventStore\Pdo\Projection\PostgresProjectionManager;
use Prooph\EventStore\Projection\MetadataAwareProjector;
use Prooph\EventStore\Projection\MetadataAwareReadModelProjector;
use Prooph\EventStore\Projection\ProjectionManager;
use Prooph\EventStore\Projection\ProjectionStatus;
use Prooph\EventStore\Projection\Projector;
use Prooph\EventStore\Projection\Query;
use Prooph\EventStore\Projection\ReadModel;
use Prooph\EventStore\Projection\ReadModelProjector;
use function str_contains;

/**
 * licence Apache-2.0
 */
class LazyProophProjectionManager implements ProjectionManager
{
    /** @var LazyProophProjectionManager[] */
    private array $lazyInitializedProjectionManager = [];

    /**
     * @param array<string, ProjectionSetupConfiguration> $projectionSetupConfigurations
     */
    public function __construct(
        private EventSourcingConfiguration $eventSourcingConfiguration,
        private array                      $projectionSetupConfigurations,
        private MessagingEntrypointWithHeadersPropagation        $messagingEntrypoint,
        private ConversionService          $conversionService,
        private LazyProophEventStore       $lazyProophEventStore,
    ) {
    }

    private function getProjectionManager(): ProjectionManager
    {
        $context = $this->lazyProophEventStore->getContextName();
        if (isset($this->lazyInitializedProjectionManager[$context])) {
            return $this->lazyInitializedProjectionManager[$context];
        }

        $eventStore = $this->getLazyProophEventStore();

        $this->lazyInitializedProjectionManager[$context] = match ($eventStore->getEventStoreType()) {
            LazyProophEventStore::EVENT_STORE_TYPE_POSTGRES => new PostgresProjectionManager($eventStore->getEventStore(), $eventStore->getWrappedConnection(), $this->eventSourcingConfiguration->getEventStreamTableName(), $this->eventSourcingConfiguration->getProjectionsTable()),
            LazyProophEventStore::EVENT_STORE_TYPE_MYSQL => new MySqlProjectionManager($eventStore->getEventStore(), $eventStore->getWrappedConnection(), $this->eventSourcingConfiguration->getEventStreamTableName(), $this->eventSourcingConfiguration->getProjectionsTable()),
            LazyProophEventStore::EVENT_STORE_TYPE_MARIADB => new MariaDbProjectionManager($eventStore->getEventStore(), $eventStore->getWrappedConnection(), $this->eventSourcingConfiguration->getEventStreamTableName(), $this->eventSourcingConfiguration->getProjectionsTable()),
            LazyProophEventStore::EVENT_STORE_TYPE_IN_MEMORY => $this->eventSourcingConfiguration->getInMemoryProjectionManager()
        };

        return $this->lazyInitializedProjectionManager[$context];
    }

    public function ensureEventStoreIsPrepared(): void
    {
        $this->getLazyProophEventStore()->prepareEventStore();
    }

    public function createQuery(): Query
    {
        return $this->getProjectionManager()->createQuery();
    }

    public function createProjection(string $name, array $options = []): Projector
    {
        $options = $this->resolveGapDetection($options);

        $projection = $this->getProjectionManager()->createProjection($name, $options);

        $metadataMatcher = $options[ProjectionRunningConfiguration::OPTION_METADATA_MATCHER] ?? null;

        if ($metadataMatcher instanceof MetadataMatcher && $projection instanceof MetadataAwareProjector) {
            $projection = $projection->withMetadataMatcher($metadataMatcher->build());
        }

        return $projection;
    }

    public function createReadModelProjection(string $name, ReadModel $readModel, array $options = []): ReadModelProjector
    {
        $options = $this->resolveGapDetection($options);

        $projection = $this->getProjectionManager()->createReadModelProjection($name, $readModel, $options);

        $metadataMatcher = $options[ProjectionRunningConfiguration::OPTION_METADATA_MATCHER] ?? null;

        if ($metadataMatcher instanceof MetadataMatcher && $projection instanceof MetadataAwareReadModelProjector) {
            $projection = $projection->withMetadataMatcher($metadataMatcher->build());
        }

        return $projection;
    }

    public function deleteProjection(string $name, bool $deleteEmittedEvents): void
    {
        try {
            $this->getProjectionManager()->deleteProjection($name, $deleteEmittedEvents);
            $this->triggerActionOnProjection($name);
        } catch (ProjectionNotFound) {
        }
    }

    public function resetProjection(string $name): void
    {
        $this->getProjectionManager()->resetProjection($name);
        $this->triggerActionOnProjection($name);
    }

    public function triggerProjection(string $name): void
    {
        $this->triggerActionOnProjection($name);
    }

    public function initializeProjection(string $name): void
    {
        $this->messagingEntrypoint->send([], $this->projectionSetupConfigurations[$name]->getInitializationChannelName());
        $this->triggerActionOnProjection($name);
    }

    public function stopProjection(string $name): void
    {
        $this->getProjectionManager()->stopProjection($name);

        $this->triggerActionOnProjection($name);
    }

    public function hasInitializedProjectionWithName(string $name): bool
    {
        $this->ensureEventStoreIsPrepared();

        return (bool)$this->getProjectionManager()->fetchProjectionNames($name, 1, 0);
    }

    public function getProjectionStatus(string $name): \Ecotone\EventSourcing\ProjectionStatus
    {
        $this->ensureEventStoreIsPrepared();

        return match ($this->getProjectionManager()->fetchProjectionStatus($name)->getValue()) {
            ProjectionStatus::DELETING, ProjectionStatus::DELETING_INCL_EMITTED_EVENTS => \Ecotone\EventSourcing\ProjectionStatus::DELETING(),
            ProjectionStatus::STOPPING, ProjectionStatus::RUNNING => \Ecotone\EventSourcing\ProjectionStatus::RUNNING(),
            ProjectionStatus::RESETTING => \Ecotone\EventSourcing\ProjectionStatus::REBUILDING(),
            ProjectionStatus::IDLE => \Ecotone\EventSourcing\ProjectionStatus::IDLE(),
        };
    }

    public function fetchProjectionNames(?string $filter, int $limit = 20, int $offset = 0): array
    {
        $this->ensureEventStoreIsPrepared();

        return $this->getProjectionManager()->fetchProjectionNames($filter, $limit, $offset);
    }

    public function fetchProjectionNamesRegex(string $regex, int $limit = 20, int $offset = 0): array
    {
        $this->ensureEventStoreIsPrepared();

        return $this->getProjectionManager()->fetchProjectionNamesRegex($regex, $limit, $offset);
    }

    public function fetchProjectionStatus(string $name): ProjectionStatus
    {
        $this->ensureEventStoreIsPrepared();

        return $this->getProjectionManager()->fetchProjectionStatus($name);
    }

    public function fetchProjectionStreamPositions(string $name): array
    {
        $this->ensureEventStoreIsPrepared();

        return $this->getProjectionManager()->fetchProjectionStreamPositions($name);
    }

    public function getProjectionState(string $name): array
    {
        return $this->getProjectionManager()->fetchProjectionState($name);
    }

    public function fetchProjectionState(string $name): array
    {
        $this->ensureEventStoreIsPrepared();

        return $this->getProjectionManager()->fetchProjectionState($name);
    }

    public function run(string $projectionName, ProjectionStreamSource $projectionStreamSource, array $projectionConfiguration, \Ecotone\EventSourcing\ProjectionStatus $status): void
    {
        $projection = $this->createReadModelProjection($projectionName, new ProophReadModel(), $projectionConfiguration);
        if ($projectionStreamSource->isForAllStreams()) {
            $projection = $projection->fromAll();
        } elseif ($projectionStreamSource->getCategories()) {
            $projection = $projection->fromCategories(...$projectionStreamSource->getCategories());
        } elseif ($projectionStreamSource->getStreams()) {
            $projection = $projection->fromStreams(...$projectionStreamSource->getStreams());
        }

        $messagingEntrypoint = $this->messagingEntrypoint;
        $routerChannel = $this->projectionSetupConfigurations[$projectionName]->getActionRouterChannel();
        $conversionService = $this->conversionService;
        $projection = $projection->whenAny(function ($state, Message $event) use ($projectionName, $status, $messagingEntrypoint, $routerChannel, $conversionService): mixed {
            $state = $messagingEntrypoint->sendWithHeaders(
                $event->payload(),
                array_merge(
                    $event->metadata(),
                    [
                        ProjectionEventHandler::PROJECTION_STATE => $state,
                        ProjectionEventHandler::PROJECTION_EVENT_NAME => $event->messageName(),
                        ProjectionEventHandler::PROJECTION_IS_REBUILDING => $status == \Ecotone\EventSourcing\ProjectionStatus::REBUILDING(),
                        ProjectionEventHandler::PROJECTION_NAME => $projectionName,
                        MessageHeaders::STREAM_BASED_SOURCED => true,
                    ]
                ),
                $routerChannel,
            );

            if (! is_null($state)) {
                $stateType = TypeDescriptor::createFromVariable($state);
                if (! $stateType->isArrayButNotClassBasedCollection()) {
                    $state = $conversionService->convert(
                        $state,
                        $stateType,
                        MediaType::createApplicationXPHP(),
                        TypeDescriptor::createArrayType(),
                        MediaType::createApplicationXPHP()
                    );
                }
            }

            return $state;
        });

        try {
            $projection->run(false);
        } catch (RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'Another projection process is already running')) {
                throw $exception;
            }

            sleep(1);
            $projection->run(false);
        }
    }

    public function getLazyProophEventStore(): LazyProophEventStore
    {
        return $this->lazyProophEventStore;
    }

    private function triggerActionOnProjection(string $name): void
    {
        $this->messagingEntrypoint->send([], $this->projectionSetupConfigurations[$name]->getTriggeringChannelName());
    }

    public static function getProjectionStreamName(string $name): string
    {
        return 'projection_' . $name;
    }

    private function resolveGapDetection(array $options): array
    {
        $gapDetection = $options[ProjectionRunningConfiguration::OPTION_GAP_DETECTION] ?? null;
        if ($gapDetection instanceof GapDetection) {
            $options[ProjectionRunningConfiguration::OPTION_GAP_DETECTION] = $gapDetection->build();
        }

        return $options;
    }
}
