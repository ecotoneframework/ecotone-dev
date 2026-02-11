<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Protector;

use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

class DataEncryptor implements Converter
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
        $source = json_decode($source, true);

        foreach ($this->sensitiveProperties as $property) {
            if (! array_key_exists($property, $source)) {
                continue;
            }

            if (! in_array($property, $this->scalarProperties, true)) {
                $source[$property] = json_encode($source[$property]);
            }

            $source[$property] = base64_encode(Crypto::encrypt($source[$property], $this->encryptionKey));
        }

        return json_encode($source);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->isCompatibleWith($this->supportedType) && $targetMediaType->isCompatibleWith(MediaType::createApplicationJsonEncrypted());
    }
}
