<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config\Polling;

use Ecotone\Messaging\Config\Configuration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Config\Routing\RoutingEvent;
use Ecotone\Modelling\Config\Routing\RoutingEventHandler;
use Ecotone\Projecting\Attribute\Polling;
use Ecotone\Projecting\Attribute\Projection;

/**
 * This routing event handler cancels routing for polling projections in the new Ecotone projection system.
 * Polling projections are triggered by inbound channel adapters instead of event-driven routing.
 * licence Enterprise
 */
class ProophPollingProjectionRoutingExtension implements RoutingEventHandler
{
    public function handleRoutingEvent(RoutingEvent $event, ?Configuration $messagingConfiguration = null): void
    {
        $registration = $event->getRegistration();
        $isEventHandler = $registration->hasAnnotation(EventHandler::class);
        if ($isEventHandler && $event->getRegistration()->hasAnnotation(Projection::class)) {
            // Cancel routing if projection has #[Polling] attribute
            if ($registration->hasAnnotation(Polling::class)) {
                $event->cancel();
            }
        }
    }
}
