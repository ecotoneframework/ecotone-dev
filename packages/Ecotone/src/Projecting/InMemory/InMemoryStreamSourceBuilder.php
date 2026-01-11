<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\InMemory;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\DefinedObjectWrapper;
use Ecotone\Projecting\StreamSourceReference;

class InMemoryStreamSourceBuilder extends InMemoryStreamSource implements DefinedObject
{
    private const REFERENCE_NAME = 'in_memory_stream_source';

    public function __construct(?array $projectionNames = null, ?string $partitionField = null, array $events = [])
    {
        parent::__construct($projectionNames, $partitionField, $events);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new DefinedObjectWrapper($this);
    }

    public function getDefinition(): Definition
    {
        return new Definition(InMemoryStreamSource::class);
    }

    /**
     * @return string[]|null
     */
    public function getProjectionNames(): ?array
    {
        return $this->getHandledProjectionNames();
    }

    /**
     * @return string[]|null
     */
    private function getHandledProjectionNames(): ?array
    {
        $reflection = new \ReflectionClass(InMemoryStreamSource::class);
        $property = $reflection->getProperty('handledProjectionNames');
        return $property->getValue($this);
    }

    public function getReferenceName(): string
    {
        return self::REFERENCE_NAME;
    }

    public function toStreamSourceReference(): StreamSourceReference
    {
        $projectionNames = $this->getProjectionNames();
        return new StreamSourceReference(
            self::REFERENCE_NAME,
            $projectionNames ?? []
        );
    }
}
