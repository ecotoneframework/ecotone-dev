<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Protector;

use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

class DataDecryptor implements Converter
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
            if (!array_key_exists($property, $source)) {
                continue;
            }

            $source[$property] = Crypto::decrypt(base64_decode($source[$property]), $this->encryptionKey);

            if (! in_array($property, $this->scalarProperties, true)) {
                $source[$property] = json_decode($source[$property], true);
            }
        }

        return json_encode($source);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceMediaType->isCompatibleWith(MediaType::createApplicationJsonEncrypted()) && $targetType->isCompatibleWith($this->supportedType);
    }
}
