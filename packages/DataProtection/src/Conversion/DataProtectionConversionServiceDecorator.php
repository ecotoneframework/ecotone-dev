<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
class DataProtectionConversionServiceDecorator implements ConversionServiceDecorator
{
    private ?ConversionService $innerConversionService = null;

    public function __construct(private readonly ConversionService $dataProtectionConversionService)
    {
    }

    public function decorate(ConversionService $conversionService): void
    {
        $this->innerConversionService = $conversionService;
    }

    public function convert($source, Type $sourcePHPType, MediaType $sourceMediaType, Type $targetPHPType, MediaType $targetMediaType)
    {
        if ($this->dataProtectionConversionService->canConvert($sourcePHPType, $encryptedSourceMediaType = $sourceMediaType->addParameter('encrypted', 'true'), $targetPHPType, $targetMediaType)) {
            $source = $this->dataProtectionConversionService->convert($source, $sourcePHPType, $encryptedSourceMediaType, $targetPHPType, $targetMediaType);
        }

        $source = $this->innerConversionService->convert($source, $sourcePHPType, $sourceMediaType, $targetPHPType, $targetMediaType);

        if ($this->dataProtectionConversionService->canConvert($sourcePHPType, $sourceMediaType, $targetPHPType, $encryptedTargetMediaType = $targetMediaType->addParameter('encrypted', 'true'))) {
            $source = $this->dataProtectionConversionService->convert($source, $sourcePHPType, $sourceMediaType, $targetPHPType, $encryptedTargetMediaType);
        }

        return $source;
    }

    public function canConvert(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $this->innerConversionService->canConvert($sourceType, $sourceMediaType, $targetType, $targetMediaType);
    }
}
