<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
class DataProtectionConversionServiceDecorator implements ConversionService
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
