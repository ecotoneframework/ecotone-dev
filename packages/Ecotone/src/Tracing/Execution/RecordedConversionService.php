<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Type;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedConversionService implements ConversionService
{
    public function __construct(private ConversionService $conversionService, private ExecutionRecordingTracer $tracer)
    {
    }

    public function convert($source, Type $sourcePHPType, MediaType $sourceMediaType, Type $targetPHPType, MediaType $targetMediaType)
    {
        $step = $this->tracer->startStep('convert ' . self::typeOf($sourcePHPType) . ' (' . $sourceMediaType->toString() . ') to ' . self::typeOf($targetPHPType) . ' (' . $targetMediaType->toString() . ')');
        try {
            $converted = $this->conversionService->convert($source, $sourcePHPType, $sourceMediaType, $targetPHPType, $targetMediaType);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, '');
        }

        return $converted;
    }

    public function canConvert(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $this->conversionService->canConvert(sourceType: $sourceType, sourceMediaType: $sourceMediaType, targetType: $targetType, targetMediaType: $targetMediaType);
    }

    private static function typeOf(Type $type): string
    {
        return ExecutionRecordingTracer::shortClassOf($type->toString());
    }
}
