<?php

declare(strict_types=1);

namespace Ecotone\Api;

use Attribute;
use Ecotone\Messaging\Attribute\InputOutputEndpointAnnotation;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class ServiceActivator extends InputOutputEndpointAnnotation
{
    private bool $requiresReply;

    public function __construct(
        string $inputChannelName,
        string $endpointId = '',
        string $outputChannelName = '',
        bool $requiresReply = false,
        array $requiredInterceptorNames = [],
        private bool $changingHeaders = false,
    ) {
        parent::__construct($inputChannelName, $endpointId, $outputChannelName, $requiredInterceptorNames);
        $this->requiresReply = $requiresReply;
    }

    public function isRequiresReply(): bool
    {
        return $this->requiresReply;
    }

    public function isChangingHeaders(): bool
    {
        return $this->changingHeaders;
    }
}
