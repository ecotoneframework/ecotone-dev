<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Flow;

use Ecotone\Test\Flow\FlowScenario;

/**
 * licence Apache-2.0
 */
final class CrossModuleFlowScenarioCatalogue
{
    /**
     * @return iterable<string, array{FlowScenario}>
     */
    public static function dataProvider(): iterable
    {
        foreach (self::all() as $scenario) {
            yield $scenario->name => [$scenario];
        }
    }

    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            ...DbalFlowScenarios::all(),
        ];
    }
}
