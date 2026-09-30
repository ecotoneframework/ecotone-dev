<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Api\Conversion\ConversionService;

/**
 * licence Enterprise
 */
interface ConversionServiceDecorator extends ConversionService
{
    public function decorate(ConversionService $conversionService): void;
}
