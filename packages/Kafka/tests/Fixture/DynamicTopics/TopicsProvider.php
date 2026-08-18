<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\DynamicTopics;

/**
 * licence Enterprise
 */
final class TopicsProvider
{
    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return ['dynamicOrdersTopic'];
    }
}
