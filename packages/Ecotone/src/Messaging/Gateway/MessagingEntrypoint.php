<?php

namespace Ecotone\Messaging\Gateway;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
interface MessagingEntrypoint
{
    public const ENTRYPOINT = 'ecotone.messaging.entrypoint';

    public function send(#[Payload] $payload, #[Header(MessagingEntrypoint::ENTRYPOINT)] string $targetChannel): mixed;

    public function sendWithHeaders(#[Payload] $payload, #[Headers] array $headers, #[Header(MessagingEntrypoint::ENTRYPOINT)] string $targetChannel): mixed;

    public function sendWithHeadersWithMessageReply(#[Payload] $payload, #[Headers] array $headers, #[Header(MessagingEntrypoint::ENTRYPOINT)] string $targetChannel): ?Message;

    /**
     * It must contain {MessagingEntrypoint::ENTRYPOINT} header
     */
    public function sendMessage(Message $message): mixed;
}
