<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

/**
 * licence Apache-2.0
 */
final class FlowScenario
{
    /**
     * @param list<class-string> $classesToResolve
     * @param array<string|int, object> $services
     * @param list<object> $extensionObjects
     * @param list<FlowStage> $stages
     */
    public function __construct(
        public readonly string $name,
        public readonly array $classesToResolve,
        public readonly array $services,
        public readonly array $extensionObjects,
        public readonly array $stages,
    ) {
    }
}
