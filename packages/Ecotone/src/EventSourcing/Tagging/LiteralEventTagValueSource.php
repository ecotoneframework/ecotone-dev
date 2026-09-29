<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

/**
 * licence Enterprise
 */
final class LiteralEventTagValueSource implements EventTagValueSource
{
    public function __construct(
        private readonly string $tagName,
        private readonly string $value,
    ) {
    }

    public function tagName(): string
    {
        return $this->tagName;
    }

    public function resolveValues(object $event): array
    {
        return EventTagValueNormalizer::normalize($this->tagName, $this->value);
    }
}
