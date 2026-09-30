<?php

declare(strict_types=1);

namespace Ecotone\Messaging\MessageConverter;

use Ecotone\Api\Conversion\ConversionService;
use Ecotone\Messaging\Config\Container\DefinedObject;

/**
 * Interface HeaderMapper
 * @package Ecotone\Messaging\Handler
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface HeaderMapper extends DefinedObject
{
    /**
     * @param array $headersToBeMapped
     * @return array
     */
    public function mapToMessageHeaders(array $headersToBeMapped, ConversionService $conversionService): array;

    /**
     * @param array $headersToBeMapped
     * @return array
     */
    public function mapFromMessageHeaders(array $headersToBeMapped, ConversionService $conversionService): array;
}
