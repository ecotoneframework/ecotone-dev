<?php

namespace Ecotone\Lite;

use Ecotone\Messaging\Handler\ReferenceNotFoundException;

/**
 * Class InMemoryPSRContainer
 * @package Ecotone\Lite
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class InMemoryPSRContainerInterfaceWithSet implements ContainerInterfaceWithSet
{
    private array $objects;

    /**
     * InMemoryPSRContainer constructor.
     * @param array $objects
     */
    private function __construct(array $objects)
    {
        $this->objects = $objects;
    }

    /**
     * @param object[] $objects
     * @return InMemoryPSRContainerInterfaceWithSet
     */
    public static function createFromAssociativeArray(array $objects): self
    {
        return new self($objects);
    }

    /**
     * @param array $objects
     * @return InMemoryPSRContainerInterfaceWithSet
     */
    public static function createFromObjects(array $objects): self
    {
        $map = [];
        foreach ($objects as $key => $object) {
            $map[is_numeric($key) ? get_class($object) : $key] = $object;
        }

        return new self($map);
    }

    /**
     * @return InMemoryPSRContainerInterfaceWithSet
     */
    public static function createEmpty(): self
    {
        return self::createFromAssociativeArray([]);
    }

    public function setService(string $referenceName, object $service): void
    {
        $this->objects[$referenceName] = $service;
    }

    /**
     * @inheritDoc
     */
    public function get($id)
    {
        if (! isset($this->objects[$id])) {
            throw ReferenceNotFoundException::create("Reference with id {$id} was not found");
        }

        return $this->objects[$id];
    }

    public function set(string $id, object $object): void
    {
        $this->objects[$id] = $object;
    }

    /**
     * @inheritDoc
     */
    public function has($id): bool
    {
        return array_key_exists($id, $this->objects);
    }
}
