<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class TenantClosurePoller
{
    public function __construct(private array $pending)
    {
    }

    #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
    #[WithTenantResolver(expression: static function (#[Header('source')] string $source): string {
        return $source;
    })]
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
