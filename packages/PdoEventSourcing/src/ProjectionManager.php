<?php

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\Attribute\PropagateHeaders;

interface ProjectionManager
{
    /**
     * @param ProjectionExecutor $projectionExecutor to be called with
     * @param string[] $relatedEventClassNames events that projection is interested in. May be used for filtering the stream.
     */
    public function run(string $projectionName, ProjectionStreamSource $projectionStreamSource, ProjectionExecutor $projectionExecutor, array $relatedEventClassNames, array $projectionConfiguration): void;

    /**
     * @throws ProjectionNotFoundException
     * @param array<string, mixed> $metadata Additional metadata to be passed to projection command
     */
    #[PropagateHeaders]
    public function deleteProjection(string $name, array $metadata = []): void;

    /**
     * @throws ProjectionNotFoundException
     * @param array<string, mixed> $metadata Additional metadata to be passed to projection command
     */
    #[PropagateHeaders]
    public function resetProjection(string $name, array $metadata = []): void;

    /**
     * @throws ProjectionNotFoundException
     */
    public function stopProjection(string $name): void;

    /**
     * @throws ProjectionNotFoundException
     * @param array<string, mixed> $metadata Additional metadata to be passed to projection command
     */
    #[PropagateHeaders]
    public function initializeProjection(string $name, array $metadata = []): void;

    /**
     * @throws ProjectionNotFoundException
     * @param array<string, mixed> $metadata Additional metadata to be passed to projection command
     */
    #[PropagateHeaders]
    public function triggerProjection(string $name, array $metadata = []): void;

    public function hasInitializedProjectionWithName(string $name): bool;

    /**
     * @throws ProjectionNotFoundException
     */
    public function getProjectionStatus(string $name): ProjectionStatus;

    /**
     * @throws ProjectionNotFoundException
     */
    public function getProjectionState(string $name): array;
}
