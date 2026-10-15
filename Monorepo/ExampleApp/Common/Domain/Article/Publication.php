<?php

namespace Monorepo\ExampleApp\Common\Domain\Article;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Monorepo\ExampleApp\Common\Domain\Article\Command\ChangeContent;
use Monorepo\ExampleApp\Common\Domain\Article\Command\ChangeTitle;
use Monorepo\ExampleApp\Common\Domain\Article\Command\CreatePublication;

#[Aggregate]
class Publication
{
    public function __construct(#[Identifier] private string $id, private string $title, private string $content)
    {
    }

    #[CommandHandler]
    public static function create(CreatePublication $command): self
    {
        return new self($command->id, $command->title, $command->content);
    }

    #[CommandHandler]
    public function changeTitle(ChangeTitle $command): void
    {
        $this->title = $command->title;
    }

    #[CommandHandler]
    public function changeContent(ChangeContent $command): void
    {
        $this->content = $command->content;
    }

    #[CommandHandler('article.publish')]
    public function publish(): void
    {
    }
}