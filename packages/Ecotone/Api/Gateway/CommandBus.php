<?php

declare(strict_types=1);

namespace Ecotone\Api;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Config\MessageBusChannel;

/**
 * licence Apache-2.0
 */
interface CommandBus
{
    #[MessageGateway(MessageBusChannel::COMMAND_CHANNEL_NAME_BY_OBJECT)]
    public function send(
        #[Payload] object $command,
        #[Headers] array  $metadata = []
    ): mixed;

    #[MessageGateway(MessageBusChannel::COMMAND_CHANNEL_NAME_BY_NAME)]
    public function sendWithRouting(
        #[Header(MessageBusChannel::COMMAND_CHANNEL_NAME_BY_NAME)] string $routingKey,
        #[Payload] mixed                                                  $command = [],
        #[Header(MessageHeaders::CONTENT_TYPE)] string                    $commandMediaType = MediaType::APPLICATION_X_PHP,
        #[Headers] array                                                  $metadata = []
    ): mixed;
}
