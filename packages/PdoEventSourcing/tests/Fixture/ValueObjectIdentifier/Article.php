<?php

namespace Test\Ecotone\EventSourcing\Fixture\ValueObjectIdentifier;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;
use Ramsey\Uuid\UuidInterface;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
class Article
{
    use WithAggregateVersioning;

    #[Identifier]
    private UuidInterface $articleId;
    private string $content;

    #[CommandHandler]
    public static function publish(PublishArticle $command): array
    {
        return [new ArticleWasPublished($command->articleId, $command->content)];
    }

    #[EventSourcingHandler]
    public function apply(ArticleWasPublished $event): void
    {
        $this->articleId = $event->articleId;
        $this->content = $event->content;
    }

    #[QueryHandler('article.getContent')]
    public function getContent(): string
    {
        return $this->content;
    }
}
