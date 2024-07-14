<?php

namespace Ecotone\EventSourcing\Attribute;

use Attribute;
use Ecotone\EventSourcing\Config\InboundChannelAdapter\ProjectionEventHandler;
use Ecotone\Modelling\Attribute\AggregateIdentifier;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Apache-2.0
 */
final class ProjectionState extends AggregateIdentifier
{
    public function __construct()
    {
    }

    public function getHeaderName(): string
    {
        return ProjectionEventHandler::PROJECTION_STATE;
    }
}
