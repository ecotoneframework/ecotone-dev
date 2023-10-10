<?php

namespace Ecotone\Lite;

use Ecotone\Messaging\Handler\ReferenceNotFoundException;
use Psr\Container\ContainerInterface;

class CombinedContainer implements ContainerInterface
{
    /**
     * @var ContainerInterface[]
     */
    private array $containers;

    public function __construct(ContainerInterface ...$containers)
    {
        $this->containers = $containers;
    }


    public function get(string $id)
    {
        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return $container->get($id);
            }
        }

        throw ReferenceNotFoundException::create("Could not find reference {$id}");
    }

    public function has(string $id): bool
    {
        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return true;
            }
        }
        return false;
    }
}