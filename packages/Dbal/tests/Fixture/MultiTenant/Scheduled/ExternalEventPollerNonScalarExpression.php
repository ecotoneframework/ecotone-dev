<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class ExternalEventPollerNonScalarExpression
{
    /** @var array<int, array<string, mixed>> */
    private array $pending;

    public function __construct(array $pending = [])
    {
        $this->pending = $pending;
    }

    #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
    #[WithTenantResolver(expression: 'payload')]
    public function poll(): ?Message
    {
        if ($this->pending === []) {
            return null;
        }

        return MessageBuilder::withPayload(array_shift($this->pending))->build();
    }
}
