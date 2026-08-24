<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\Api\DataProtection\Sensitive;

#[Sensitive]
class AnnotatedClassWithAnnotatedProperty
{
    public function __construct(
        #[Sensitive] public string $sensitiveProperty
    ) {
    }
}
