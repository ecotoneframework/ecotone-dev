<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Attribute\Sensitive;

class MessageWithSensitiveProperty
{
    public function __construct(
        #[Sensitive]
        public string $sensitiveProperty,
        public string $nonSensitiveProperty,
    ) {
    }
}
