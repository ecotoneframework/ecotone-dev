<?php

declare(strict_types=1);

namespace Ecotone\Lite\Test;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\Config\ModellingHandlerModule;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;

final class FlowTestSupport
{
    public function __construct(
        private CommandBus $commandBus,
        private EventBus $eventBus,
        private QueryBus $queryBus,
        private MessagingTestSupport $testSupportGateway,
        private MessagingEntrypoint $messagingEntrypoint
    )
    {}

    public function sendCommand(object $command, array $metadata = []): self
    {
        $this->commandBus->send($command, $metadata);

        return $this;
    }

    public function sendCommandWithRoutingKey(string $routingKey, mixed $command = [], string $commandMediaType = MediaType::APPLICATION_X_PHP, array $metadata = []): self
    {
        $this->commandBus->sendWithRouting($routingKey, $command, $commandMediaType, $metadata);

        return $this;
    }

    public function publishEvent(object $event): self
    {
        $this->eventBus->publish($event);

        return $this;
    }

    public function publishEventWithRoutingKey(string $routingKey, mixed $event = [], string $eventMediaType = MediaType::APPLICATION_X_PHP, array $metadata = []): self
    {
        $this->eventBus->publishWithRouting($routingKey, $event, $eventMediaType, $metadata);

        return $this;
    }

    public function sendQuery(object $query, array $metadata = [], ?string $expectedReturnedMediaType = null): mixed
    {
        return $this->queryBus->send($query, $metadata, $expectedReturnedMediaType);
    }

    public function discardRecordedMessages(): self
    {
        $this->testSupportGateway->discardRecordedMessages();

        return $this;
    }

    public function releaseMessagesAwaitingFor(string $channelName, int $timeInMilliseconds): self
    {
        $this->testSupportGateway->releaseMessagesAwaitingFor($channelName, $timeInMilliseconds);

        return $this;
    }

    public function sendQueryWithRouting(string $routingKey, mixed $query = [], string $queryMediaType = MediaType::APPLICATION_X_PHP, array $metadata = [], ?string $expectedReturnedMediaType = null): mixed
    {
        return $this->queryBus->sendWithRouting($routingKey, $query, $queryMediaType, $metadata, $expectedReturnedMediaType);
    }

    /**
     * @return mixed[]
     */
    public function getRecordedEvents(): array
    {
        return $this->testSupportGateway->getRecordedEvents();
    }

    /**
     * @return mixed[]
     */
    public function getRecordedCommands(): array
    {
        return $this->testSupportGateway->getRecordedCommands();
    }

    /**
     * @template T
     * @param T $className
     * @param string|array $identifiers
     * @return T
     */
    public function getAggregate(string $className, string|array $identifiers): object
    {
        return $this->messagingEntrypoint->sendWithHeaders(
            [],
            [
                AggregateMessage::OVERRIDE_AGGREGATE_IDENTIFIER => $identifiers
            ],
            ModellingHandlerModule::getRegisterAggregateLoadRepositoryInputChannel($className)
        );
    }

    /**
     * @template T
     * @param T $className
     * @param string|array $identifiers
     * @return T
     */
    public function getSaga(string $className, string|array $identifiers): object
    {
        return $this->getAggregate($className, $identifiers);
    }
}