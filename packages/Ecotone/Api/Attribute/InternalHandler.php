<?php

declare(strict_types=1);

namespace Ecotone\Api\Attribute;

use Attribute;
use Ecotone\Messaging\Attribute\InputOutputEndpointAnnotation;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
/**
 * licence Apache-2.0
 */
class InternalHandler extends InputOutputEndpointAnnotation
{
    public function __construct(
        string $inputChannelName,
        string $outputChannelName = '',
        string $endpointId = '',
        array $requiredInterceptorNames = [],
        private bool $changingHeaders = false,
        private bool $requiresReply = false,
    ) {
        parent::__construct($inputChannelName, $endpointId, $outputChannelName, $requiredInterceptorNames);
    }

    public function isChangingHeaders(): bool
    {
        return $this->changingHeaders;
    }

    public function isRequiresReply(): bool
    {
        return $this->requiresReply;
    }
}
