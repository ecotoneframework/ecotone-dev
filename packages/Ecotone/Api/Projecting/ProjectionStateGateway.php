<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Api;

use Attribute;

#[Attribute]
final class ProjectionStateGateway
{
    public function __construct(private string $projectionName, private string $projectionManagerReference = ProjectingManager::class)
    {
    }

    public function getProjectionName(): string
    {
        return $this->projectionName;
    }

    public function getProjectionManagerReference(): string
    {
        return $this->projectionManagerReference;
    }
}
