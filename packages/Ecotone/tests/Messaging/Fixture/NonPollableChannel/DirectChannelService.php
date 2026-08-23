<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\NonPollableChannel;

use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class DirectChannelService
{
    public const DIRECT_CHANNEL = 'nonPollableDirectChannel';
    public const TRIGGER_ROUTING_KEY = 'nonPollable.trigger';
    public const TRIGGER_OBJECT_ROUTING_KEY = 'nonPollable.triggerObject';

    public array $handled = [];
    public array $handledPayloadTypes = [];
    public array $handledDuringCommandHandling = [];
    public mixed $replyDuringCommandHandling = null;

    #[CommandHandler(self::TRIGGER_ROUTING_KEY)]
    public function trigger(string $payload, #[Reference] MessagingEntrypointService $messagingEntrypoint): void
    {
        $this->replyDuringCommandHandling = $messagingEntrypoint->send($payload, self::DIRECT_CHANNEL);
        $this->handledDuringCommandHandling = $this->handled;
    }

    #[CommandHandler(self::TRIGGER_OBJECT_ROUTING_KEY)]
    public function triggerWithObject(string $payload, #[Reference] MessagingEntrypointService $messagingEntrypoint): void
    {
        $messagingEntrypoint->send(new DirectChannelPayload($payload), self::DIRECT_CHANNEL);
    }

    #[InternalHandler(inputChannelName: self::DIRECT_CHANNEL)]
    public function receive(mixed $payload): string
    {
        $this->handled[] = is_string($payload) ? $payload : $payload->name;
        $this->handledPayloadTypes[] = get_debug_type($payload);

        return 'handled-' . (is_string($payload) ? $payload : $payload->name);
    }
}
