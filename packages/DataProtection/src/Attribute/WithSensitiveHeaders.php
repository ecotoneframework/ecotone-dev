<?php

/**
 * licence Enterprise
 */
namespace Ecotone\DataProtection\Attribute;

use Attribute;
use Ecotone\Messaging\Support\Assert;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class WithSensitiveHeaders
{
    public function __construct(public array $headers)
    {
        Assert::allStrings($this->headers, 'Header names should be all strings.');
    }
}
