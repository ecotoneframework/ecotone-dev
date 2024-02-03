<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class ChannelInterceptor
{
    public function __construct(
        private string $channelName,
        private bool   $changeHeaders = false,
    ) {}
}