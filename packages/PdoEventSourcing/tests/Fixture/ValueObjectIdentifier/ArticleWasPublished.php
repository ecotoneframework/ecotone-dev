<?php

namespace Test\Ecotone\EventSourcing\Fixture\ValueObjectIdentifier;

use Ramsey\Uuid\UuidInterface;

/**
 * licence Apache-2.0
 */
class ArticleWasPublished
{
    public function __construct(public UuidInterface $articleId, public string $content)
    {
    }
}
