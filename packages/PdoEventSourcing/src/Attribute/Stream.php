<?php

namespace Ecotone\EventSourcing\Attribute;

use Attribute;
use Ecotone\Messaging\Support\Assert;

#[Attribute(Attribute::TARGET_CLASS)]
/**
 * licence Apache-2.0
 */
class Stream
{
    private string $name;

    public function __construct(string $name)
    {
        Assert::notNullAndEmpty($name, "Stream name can't be empty");

        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
