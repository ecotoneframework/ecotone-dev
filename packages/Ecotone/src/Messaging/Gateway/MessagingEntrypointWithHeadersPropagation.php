<?php

namespace Ecotone\Messaging\Gateway;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;

/**
 * licence Apache-2.0
 */
interface MessagingEntrypointWithHeadersPropagation
{
    public function send(#[Payload] $payload, #[Header(MessagingEntrypointService::ENTRYPOINT)] string $targetChannel): mixed;

    public function sendWithHeaders(#[Payload] $payload, #[Headers] array $headers, #[Header(MessagingEntrypointService::ENTRYPOINT)] string $targetChannel, #[Header(MessageHeaders::ROUTING_SLIP)] ?string $routingSlip = null): mixed;

    public function sendWithHeadersWithMessageReply(#[Payload] $payload, #[Headers] array $headers, #[Header(MessagingEntrypointService::ENTRYPOINT)] string $targetChannel, #[Header(MessageHeaders::ROUTING_SLIP)] ?string $routingSlip = null): ?Message;

    public function sendMessage(Message $message): mixed;
}
