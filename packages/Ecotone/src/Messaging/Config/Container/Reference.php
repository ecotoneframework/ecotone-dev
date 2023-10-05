<?php

namespace Ecotone\Messaging\Config\Container;

class Reference
{
    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }

}