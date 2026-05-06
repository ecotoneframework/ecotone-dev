<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class ExternalEventReceiver
{
    /** @var array<int, array<string, mixed>> */
    private array $captured = [];

    #[Asynchronous('external_processing')]
    #[CommandHandler('externalEventArrived', endpointId: 'externalEventArrivedEndpoint')]
    public function handle(mixed $payload, #[Headers] array $headers): void
    {
        $this->captured[] = $headers;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[QueryHandler('lastCapturedHeaders')]
    public function lastCapturedHeaders(): ?array
    {
        return array_shift($this->captured);
    }
}
