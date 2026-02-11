<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion;

use Ecotone\DataProtection\Protector\DataEncryptor;
use Ecotone\Messaging\Handler\Type;

use function is_iterable;

/**
 * licence Apache-2.0
 */
class AutoCollectionConversionService implements ConversionService
{
    /**
     * @param Converter[] $converters
     * @param DataEncryptor[] $dataProtectors
     * @param array<string, int|false> $convertersCache value is index of converter in $this->converters or false if not found
     * @param array<string, int|false> $protectorsCache value is index of data protector in $this->dataProtectors or false if not found
     */
    public function __construct(private array $converters, private array $dataProtectors, private array $convertersCache = [], private array $protectorsCache = [])
    {
    }

    /**
     * @param Converter[] $converters
     * @return AutoCollectionConversionService
     */
    public static function createWith(array $converters, array $dataProtectors = []): self
    {
        return new self($converters, $dataProtectors);
    }

    /**
     * @return AutoCollectionConversionService
     */
    public static function createEmpty(): self
    {
        return new self([], []);
    }

    public function convert($source, Type $sourcePHPType, MediaType $sourceMediaType, Type $targetPHPType, MediaType $targetMediaType)
    {
        if (is_null($source)) {
            return $source;
        }

        // check if a source can be decrypted
        if ($sourceMediaType->isCompatibleWith(MediaType::createApplicationJson()) && $dataDecryptor = $this->getDataProtector($sourcePHPType, MediaType::createApplicationJsonEncrypted(), $targetPHPType, $targetMediaType)) {
            $source = $dataDecryptor->convert($source, $sourcePHPType, MediaType::createApplicationJsonEncrypted(), $targetPHPType, $targetMediaType);
        }

        // run actual conversion
        if ($converter = $this->getConverter($sourcePHPType, $sourceMediaType, $targetPHPType, $targetMediaType)) {
            $convertedValue = $converter->convert($source, $sourcePHPType, $sourceMediaType, $targetPHPType, $targetMediaType, $this);

            // check if a source can be encrypted
            if ($targetMediaType->isCompatibleWith(MediaType::createApplicationJson()) && $dataEncryptor = $this->getDataProtector($sourcePHPType, $sourceMediaType, $targetPHPType, MediaType::createApplicationJsonEncrypted())) {
                return $dataEncryptor->convert($convertedValue, $sourcePHPType, $sourceMediaType, $targetPHPType, MediaType::createApplicationJsonEncrypted());
            }

            return $convertedValue;
        }

        if (is_iterable($source) && $targetPHPType->isIterable() && $targetPHPType instanceof Type\GenericType) {
            $converted = [];
            $targetValueType = $this->getValueTypeFromCollectionType($targetPHPType);
            foreach ($source as $k => $v) {
                $converted[$k] = $this->convert($v, Type::createFromVariable($v), MediaType::createApplicationXPHP(), $targetValueType, $targetMediaType);
            }
            return $converted;
        }

        throw ConversionException::create("Converter was not found for {$sourceMediaType}:{$sourcePHPType} to {$targetMediaType}:{$targetPHPType};");
    }

    public function canConvert(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        if ($this->getConverter($sourceType, $sourceMediaType, $targetType, $targetMediaType)) {
            return true;
        }
        if ($sourceType->isIterable() && $sourceType instanceof Type\GenericType
            && $targetType->isIterable() && $targetType instanceof Type\GenericType) {
            return (bool) $this->getConverter(
                $this->getValueTypeFromCollectionType($sourceType),
                $sourceMediaType,
                $this->getValueTypeFromCollectionType($targetType),
                $targetMediaType
            );
        }
        return false;
    }

    private function getValueTypeFromCollectionType(Type\GenericType $collectionType): Type
    {
        return match (count($collectionType->genericTypes)) {
            1 => $collectionType->genericTypes[0],
            default => $collectionType->genericTypes[1],
        };
    }

    /**
     * @param Type $sourceType
     * @param MediaType $sourceMediaType
     * @param Type $targetType
     * @param MediaType $targetMediaType
     * @return Converter|null
     */
    private function getConverter(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): ?Converter
    {
        $cacheKey = $sourceType->toString() . '|' . $sourceMediaType->toString() . '->' . $targetType->toString() . '|' . $targetMediaType->toString();
        if (isset($this->convertersCache[$cacheKey])) {
            if ($this->convertersCache[$cacheKey] === false) {
                return null;
            }
            return $this->converters[$this->convertersCache[$cacheKey]];
        }

        foreach ($this->converters as $index => $converter) {
            if ($converter->matches($sourceType, $sourceMediaType, $targetType, $targetMediaType)) {
                $this->convertersCache[$cacheKey] = $index;
                return $converter;
            }
        }

        $this->convertersCache[$cacheKey] = false;

        return null;
    }

    private function getDataProtector(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): ?Converter
    {
        $cacheKey = $sourceType->toString() . '|' . $sourceMediaType->toString() . '->' . $targetType->toString() . '|' . $targetMediaType->toString();
        if (isset($this->protectorsCache[$cacheKey])) {
            if ($this->protectorsCache[$cacheKey] === false) {
                return null;
            }
            return $this->dataProtectors[$this->protectorsCache[$cacheKey]];
        }

        foreach ($this->dataProtectors as $index => $protector) {
            if ($protector->matches($sourceType, $sourceMediaType, $targetType, $targetMediaType)) {
                $this->protectorsCache[$cacheKey] = $index;
                return $protector;
            }
        }

        $this->protectorsCache[$cacheKey] = false;

        return null;
    }
}
