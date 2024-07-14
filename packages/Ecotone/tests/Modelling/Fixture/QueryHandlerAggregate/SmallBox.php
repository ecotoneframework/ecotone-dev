<?php

namespace Test\Ecotone\Modelling\Fixture\QueryHandlerAggregate;

/**
 * licence Apache-2.0
 */
class SmallBox implements Box
{
    private string $boxId;

    private function __construct(string $boxId)
    {
        $this->boxId = $boxId;
    }

    public static function create(string $boxId): static
    {
        return new self($boxId);
    }

    public function getId(): string
    {
        return $this->boxId;
    }
}
