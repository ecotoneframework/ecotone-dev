<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Configuration;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Modelling\DecisionModel\DecisionModelBatchLoader;
use Ecotone\OpenTelemetry\TracedDecisionModelBatchLoader;
use Ecotone\OpenTelemetry\TracedEventStore;
use OpenTelemetry\API\Trace\TracerProviderInterface;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * licence Apache-2.0
 */
final class TraceDynamicConsistencyBoundaryCompilerPass implements CompilerPass
{
    private const BATCH_LOADER_REFERENCE_PREFIX = 'decisionModel.batchLoader.';

    public function process(ContainerBuilder $builder): void
    {
        $this->traceEachDecisionModelLoad($builder);
        $this->traceConditionalAppends($builder);
    }

    private function traceEachDecisionModelLoad(ContainerBuilder $builder): void
    {
        foreach ($builder->getDefinitions() as $id => $definition) {
            if (! str_starts_with($id, self::BATCH_LOADER_REFERENCE_PREFIX) || ! $definition instanceof Definition || $definition->getClassName() !== DecisionModelBatchLoader::class) {
                continue;
            }

            $builder->replace($id, new Definition(TracedDecisionModelBatchLoader::class, [
                $definition,
                substr($id, strlen(self::BATCH_LOADER_REFERENCE_PREFIX)),
                new Reference(TracerProviderInterface::class),
            ]));
        }
    }

    private function traceConditionalAppends(ContainerBuilder $builder): void
    {
        if (! $builder->has(EventStore::RAW_REFERENCE)) {
            return;
        }

        $builder->replace(EventStore::RAW_REFERENCE, new Definition(TracedEventStore::class, [
            $builder->getDefinition(EventStore::RAW_REFERENCE),
            new Reference(TracerProviderInterface::class),
        ]));
    }
}
