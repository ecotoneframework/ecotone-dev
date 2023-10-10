<?php

namespace Ecotone\Messaging\Config\Container;

class Reference
{
    public function __construct(private string $id)
    {
    }

    public static function to(string $id): self
    {
        return new self($id);
    }

    public static function toChannel(string $id): ChannelReference
    {
        return new ChannelReference($id);
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
