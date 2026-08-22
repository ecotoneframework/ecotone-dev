<?php

namespace Ecotone\Modelling\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
/**
 * licence Apache-2.0
 */
class TargetIdentifier
{
    public string $identifierName = '';

    public function __construct(string $identifierName = '')
    {
        $this->identifierName = $identifierName;
    }

    public function getIdentifierName(): string
    {
        return $this->identifierName;
    }
}
