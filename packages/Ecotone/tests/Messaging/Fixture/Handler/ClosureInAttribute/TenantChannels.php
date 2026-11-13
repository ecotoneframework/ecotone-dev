<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ClosureInAttribute;

/**
 * licence Apache-2.0
 */
final class TenantChannels
{
    public function channelNameOf(string $tenantId): string
    {
        return $tenantId === 'tenant-a' ? 'sms' : 'email';
    }
}
