<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone\StandaloneAggregate;

use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourcingHandler;
use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourcingInitializer;
use Ecotone\EventSourcingV2\Ecotone\Attribute\MutatingEvents;

trait WithEventSourcingAttributes
{
    protected static array $eventHandlers = [];

    protected array $mutatingEvents = [];

    #[EventSourcingInitializer]
    public static function fromEvents(iterable $events): static
    {
        $instance = new static();
        foreach ($events as $event) {
            $instance->applyHandler($event);
        }

        return $instance;
    }

    protected static function setEventHandlersFromAttributes(): void
    {
        // this should be done ecotone framework bootstrap
        // Done here with reflection as a PoC
        $reflectionClass = new \ReflectionClass(static::class);
        foreach ($reflectionClass->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() === EventSourcingHandler::class) {
                    $methodName = $method->getName();
                    $reflectionMethod = $reflectionClass->getMethod($methodName);
                    $eventClass = $reflectionMethod->getParameters()[0]->getType()->getName();
                    self::$eventHandlers[$eventClass] = $methodName;
                }
            }
        }
    }

    #[MutatingEvents]
    public function mutatingEvents(): array
    {
        return $this->mutatingEvents;
    }

    protected function apply(object $event): void
    {
        $this->mutatingEvents[] = $event;
        $this->applyHandler($event);
    }

    protected function applyHandler(object $event): void
    {
        $eventClass = get_class($event);
        if (!self::$eventHandlers) {
            self::setEventHandlersFromAttributes();
        }
        if (!isset(self::$eventHandlers[$eventClass])) {
            throw new \RuntimeException("No handler for event {$eventClass}");
        }
        $handler = self::$eventHandlers[$eventClass];
        $this->$handler($event);
    }
}