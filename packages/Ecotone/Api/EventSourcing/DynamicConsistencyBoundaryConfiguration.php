<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

/**
 * licence Enterprise
 */
final class DynamicConsistencyBoundaryConfiguration
{
    /**
     * @param string[] $filterOnlyTagNames
     */
    private function __construct(private readonly array $filterOnlyTagNames)
    {
    }

    public static function createWithDefaults(): self
    {
        return new self([]);
    }

    /**
     * @param string[] $tagNames
     */
    public function withFilterOnlyTags(array $tagNames): self
    {
        return new self($tagNames);
    }

    /**
     * @return string[]
     */
    public function filterOnlyTagNames(): array
    {
        return $this->filterOnlyTagNames;
    }
}
