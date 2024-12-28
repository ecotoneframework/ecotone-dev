<?php

namespace Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateEvents;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut\TwitContentWasChanged;
use Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut\TwitWasCreated;

#[EventSourcingAggregate(true)]
/**
 * licence Apache-2.0
 */
class TwitterWithRecorder
{
    use WithAggregateVersioning;
    use WithAggregateEvents;

    #[Identifier]
    private string $twitId;
    private string $content;

    #[QueryHandler('getContent')]
    public function getContent(): string
    {
        return $this->content;
    }

    public static function create(string $twitId, string $content): self
    {
        $self = new self();
        $self->recordThat(new TwitWasCreated($twitId, $content));

        return $self;
    }

    #[CommandHandler('changeContent')]
    public function changeContent(string $content): void
    {
        $this->recordThat(new TwitContentWasChanged($this->twitId, $content));
    }

    #[EventSourcingHandler]
    public function whenTwitWasCreated(TwitWasCreated $event): void
    {
        $this->twitId = $event->twitId;
        $this->content = $event->content;
    }

    #[EventSourcingHandler]
    public function whenTwitContentWasChanged(TwitContentWasChanged $event): void
    {
        $this->content = $event->content;
    }
}
