<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\DataProtection\Protector\DataProtector;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

class DataProtectionConversionServiceDecorator implements ConversionService
{
    private ?ConversionService $innerConversionService = null;

    /**
     * @var DataProtector[]
     */
    private array $dataProtectors = [];

    public function withDataProtector(Type $type, DataProtector $dataProtector): void
    {
        $this->dataProtectors[$type->toString()] = $dataProtector;
    }

    public function decorate(ConversionService $conversionService): void
    {
        $this->innerConversionService = $conversionService;
    }

    public function convert($source, Type $sourcePHPType, MediaType $sourceMediaType, Type $targetPHPType, MediaType $targetMediaType)
    {
        if ($this->expectProtectedData($targetPHPType)) {
            $source = $this->decrypt($source, $this->getDataProtector($targetPHPType), $sourcePHPType->isCompatibleWith(Type::array()));
        }

        $source = $this->innerConversionService->convert($source, $sourcePHPType, $sourceMediaType, $targetPHPType, $targetMediaType);

        if ($this->expectProtectedData($sourcePHPType)) {
            $source = $this->encrypt($source, $this->getDataProtector($sourcePHPType), $targetPHPType->isCompatibleWith(Type::array()));
        }

        return $source;
    }

    public function canConvert(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $this->innerConversionService->canConvert($sourceType, $sourceMediaType, $targetType, $targetMediaType);
    }

    private function expectProtectedData(Type $type): bool
    {
        return array_key_exists($type->toString(), $this->dataProtectors);
    }

    private function getDataProtector(Type $targetPHPType): DataProtector
    {
        return $this->dataProtectors[$targetPHPType->toString()];
    }

    private function decrypt($source, DataProtector $dataProtector, bool $handleArray)
    {
        if ($handleArray) {
            $source = json_encode($source);
        }

        $source = $dataProtector->decrypt($source);

        return $handleArray ? json_decode($source, true) : $source;
    }

    private function encrypt($source, DataProtector $dataProtector, bool $handleArray)
    {
        if ($handleArray) {
            $source = json_encode($source);
        }

        $source = $dataProtector->encrypt($source);

        return $handleArray ? json_decode($source, true) : $source;
    }
}
