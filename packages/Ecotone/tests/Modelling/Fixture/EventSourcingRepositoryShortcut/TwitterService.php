<?php

namespace Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut;

use Ecotone\Api\Identifier;
use Ecotone\Api\MessageGateway;
use Ecotone\Api\Payload;

/**
 * licence Apache-2.0
 */
interface TwitterService
{
    #[MessageGateway('getContent')]
    public function getContent(#[Identifier] string $twitId): string;

    #[MessageGateway('changeContent')]
    public function changeContent(#[Identifier] string $twitId, #[Payload] string $content): void;
}
