<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
class XPhpDecryptionConverter implements Converter
{
    public function __construct(
        private Type $supportedType,
        private Key $encryptionKey,
        private array $sensitiveProperties,
        private array $scalarProperties,
    ) {
    }

    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        foreach ($this->sensitiveProperties as $property) {
            if (! array_key_exists($property, $source)) {
                continue;
            }

            $source[$property] = Crypto::decrypt($source[$property], $this->encryptionKey);

            if (! in_array($property, $this->scalarProperties, true)) {
                $source[$property] = json_decode($source[$property], true);
            }
        }

        return $source;
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $targetType->acceptType($this->supportedType) && $sourceType->isIterable() && $sourceMediaType->isCompatibleWith(MediaType::createApplicationXPHP()) && $sourceMediaType->hasParameter('encrypted');
    }
}
