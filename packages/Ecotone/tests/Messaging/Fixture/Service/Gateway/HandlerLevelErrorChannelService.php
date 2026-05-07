<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Service\Gateway;

use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\Attribute\CommandHandler;
use RuntimeException;

/**
 * licence Apache-2.0
 *
 * Demonstrates the WRONG placement of #[ErrorChannel].
 *
 * Placing the attribute on a #[CommandHandler] method has no effect — no resolver
 * looks for it on handler methods. #[ErrorChannel] must be on the messaging
 * entry-point (CommandBus, EventBus, QueryBus, MessagePublisher, #[BusinessMethod])
 * so that gateway-level interceptors (transaction rollback, retries, logging)
 * wrap the entire send. Then on failure, side effects produced by the handler
 * are rolled back BEFORE the error message is stored.
 */
final class HandlerLevelErrorChannelService
{
    public bool $sideEffectExecuted = false;

    #[CommandHandler('handler.level.error.channel.test')]
    #[ErrorChannel('handlerLevelErrorChannel')]
    public function handle(mixed $payload): void
    {
        $this->sideEffectExecuted = true;
        throw new RuntimeException('handler-failure');
    }
}
