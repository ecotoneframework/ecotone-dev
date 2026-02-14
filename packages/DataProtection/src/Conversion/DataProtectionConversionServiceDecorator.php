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
        if ($this->expectProtectedData($targetPHPType, $sourceMediaType)) {
            $source = $this->getDataProtector($targetPHPType)->decrypt($source);
        }

        $source = $this->innerConversionService->convert($source, $sourcePHPType, $sourceMediaType, $targetPHPType, $targetMediaType);

        if ($this->expectProtectedData($sourcePHPType, $targetMediaType)) {
            $source = $this->getDataProtector($sourcePHPType)->encrypt($source);
        }

        return $source;
    }

    public function canConvert(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $this->innerConversionService->canConvert($sourceType, $sourceMediaType, $targetType, $targetMediaType);
    }

    private function expectProtectedData(Type $type, MediaType $mediaType): bool
    {
        return array_key_exists($type->toString(), $this->dataProtectors) && $mediaType->isCompatibleWith(MediaType::createApplicationJson());
    }

    private function getDataProtector(Type $targetPHPType): DataProtector
    {
        return $this->dataProtectors[$targetPHPType->toString()];
    }
}
