<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Api\DataProtection\Key;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
abstract class AbstractDataProtectionConverter implements Converter
{
    public function __construct(
        protected Type $supportedType,
        protected Key $encryptionKey,
        protected array $sensitiveProperties,
        protected array $scalarProperties,
        protected array $sensitivePropertyNames,
    ) {
    }
}
