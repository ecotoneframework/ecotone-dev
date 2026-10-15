<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

use Ecotone\Api\Dbal\Attribute\WithTenantResolver;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\Messaging\Message;
use Ecotone\Test\StaticMessageBuilder;

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

        return StaticMessageBuilder::withPayload($event['payload'])
            ->setHeader('source', $event['source'])
            ->build();
    }
}
