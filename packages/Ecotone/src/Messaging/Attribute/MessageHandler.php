<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class MessageHandler extends ServiceActivator
{
    public function __construct(
        string $inputChannelName,
        string $outputChannelName = '',
        string $endpointId = '',
        array $requiredInterceptorNames = [],
        bool $changingHeaders = false,
    )
    {
        parent::__construct(
            inputChannelName: $inputChannelName,
            endpointId: $endpointId,
            outputChannelName: $outputChannelName,
            requiredInterceptorNames: $requiredInterceptorNames,
            changingHeaders: $changingHeaders,
        );
    }
}