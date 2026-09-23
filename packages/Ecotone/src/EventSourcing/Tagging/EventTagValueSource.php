<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

/**
 * licence Enterprise
 */
interface EventTagValueSource
{
    public function tagName(): string;

    /**
     * @return string[]
     */
    public function resolveValues(object $event): array;
}
