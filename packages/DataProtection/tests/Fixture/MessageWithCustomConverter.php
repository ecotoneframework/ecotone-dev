<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Attribute\Sensitive;

class MessageWithCustomConverter
{
    public function __construct(
        #[Sensitive('foo')]
        public string $sensitiveProperty,
        public string $nonSensitiveProperty,
    ) {
    }
}
