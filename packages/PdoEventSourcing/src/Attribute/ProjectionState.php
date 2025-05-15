<?php

namespace Ecotone\EventSourcing\Attribute;

use Attribute;
use Ecotone\EventSourcing\Config\InboundChannelAdapter\ProjectionEventHandler;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\AggregateIdentifier;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Apache-2.0
 */
final class ProjectionState extends Header
{
    public function __construct()
    {
        parent::__construct(ProjectionEventHandler::PROJECTION_STATE);
    }
}
