<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled;

use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class ExternalEventPollerWithoutResolver
{
    /** @var array<int, array{source: string, payload: mixed}> */
    private array $pending;

    public function __construct(array $pending = [])
    {
        $this->pending = $pending;
    }

    #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
    public function poll(): ?Message
    {
        if ($this->pending === []) {
            return null;
        }

        $event = array_shift($this->pending);
        return MessageBuilder::withPayload($event['payload'])
            ->setHeader('source', $event['source'])
            ->build();
    }
}
