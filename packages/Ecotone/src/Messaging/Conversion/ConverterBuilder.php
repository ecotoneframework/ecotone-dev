<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class ConversionServiceBuilder
 * @package Ecotone\Messaging\Conversion
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
interface ConverterBuilder extends CompilableBuilder
{
    /**
     * @return string[]
     */
    public function getRequiredReferences(): array;
}
