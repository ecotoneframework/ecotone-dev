<?php

namespace Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\Identifier;

interface TwitterService
{
    #[MessageGateway('getContent')]
    public function getContent(#[Identifier] string $twitId): string;

    #[MessageGateway('changeContent')]
    public function changeContent(#[Identifier] string $twitId, #[Payload] string $content): void;
}
