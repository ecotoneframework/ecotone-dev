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
final class ExternalEventPoller
{
    /** @var array<int, array{source: string, payload: mixed, additionalHeaders?: array<string, mixed>}> */
    private array $pending;

    public function __construct(array $pending = [])
    {
        $this->pending = $pending;
    }

    #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
    #[WithTenantResolver(expression: "headers['source']")]
    public function poll(): ?Message
    {
        if ($this->pending === []) {
            return null;
        }

        $event = array_shift($this->pending);
        $builder = MessageBuilder::withPayload($event['payload'])
            ->setHeader('source', $event['source']);

        foreach ($event['additionalHeaders'] ?? [] as $name => $value) {
            $builder = $builder->setHeader($name, $value);
        }

        return $builder->build();
    }
}
