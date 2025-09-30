<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Conversion;

interface ConversionServiceAware
{
    public function setConversionService(ConversionService $conversionService): void;
}