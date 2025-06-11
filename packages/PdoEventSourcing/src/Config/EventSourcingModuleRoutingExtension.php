<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Config\Routing\RoutingEvent;
use Ecotone\Modelling\Config\Routing\RoutingEventHandler;

/**
 * This routing event handler is responsible to trigger projection instead of routing directly to execution endpoint.
 */
class EventSourcingModuleRoutingExtension implements RoutingEventHandler
{
    /**
     * @param string[] $pollingProjectionNames
     */
    public function __construct(private array $pollingProjectionNames)
    {
    }

    public function handleRoutingEvent(RoutingEvent $event): void
    {
        $registration = $event->getRegistration();
        $isCommandOrEventHandler = $registration->hasAnnotation(CommandHandler::class) || $registration->hasAnnotation(EventHandler::class);
        if ($isCommandOrEventHandler && $event->getRegistration()->hasAnnotation(Projection::class)) {
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $event->getRegistration()->getClassAnnotationsWithType(Projection::class)[0];

            if ($projectionAttribute->enabled) {
                return; // Do not route if projection is disabled
            }

            if (in_array($projectionAttribute->getName(), $this->pollingProjectionNames, true)) {
                $event->cancel(); // Don't route if it is a polling projection
            } else {
                $event->setDestinationChannel(self::getProjectionTriggeringInputChannel($projectionAttribute->getName()));
            }
        }
    }

    private static function getProjectionTriggeringInputChannel(string $projectionName): string
    {
        return 'projection_handler_' . $projectionName;
    }
}
