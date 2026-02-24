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
class XPhpEncryptionConverter implements Converter
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

            if (! in_array($property, $this->scalarProperties, true)) {
                $source[$property] = json_encode($source[$property]);
            }

            $source[$property] = Crypto::encrypt($source[$property], $this->encryptionKey);
        }

        return $source;
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->acceptType($this->supportedType) && $targetType->isIterable() && $targetMediaType->isCompatibleWith(MediaType::createApplicationXPHP()) && $targetMediaType->hasParameter('encrypted');
    }
}
