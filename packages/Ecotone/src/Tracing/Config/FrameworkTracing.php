<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Config;

use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Lite\Test\Configuration\FlowTestingBootstrap;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class FrameworkTracing
{
    public const SWITCH_ON = 'TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)';
    public const FLOW_TESTING_SPAN_RECORDER = 'ecotone.tracing.flow_testing_span_recorder';

    public static function isTheLevelOf(array $extensionObjects): bool
    {
        return self::configurationOf($extensionObjects)->level === TracingLevel::Framework;
    }

    public static function recordsExecutionIn(array $extensionObjects): bool
    {
        return self::isTheLevelOf($extensionObjects)
            && (self::spanRecorderNamesOf($extensionObjects) !== [] || self::isFlowTesting($extensionObjects));
    }

    public static function readsTheRecordingIn(array $extensionObjects): bool
    {
        return self::isTheLevelOf($extensionObjects) && self::isFlowTesting($extensionObjects);
    }

    private static function isFlowTesting(array $extensionObjects): bool
    {
        return ExtensionObjectResolver::contains(FlowTestingBootstrap::class, $extensionObjects);
    }

    /**
     * @return list<string>
     */
    public static function spanRecorderNamesOf(array $extensionObjects): array
    {
        return [
            ...self::configurationOf($extensionObjects)->spanRecorderReferenceNames,
            ...array_map(fn (SpanRecorderReference $reference): string => $reference->referenceName, ExtensionObjectResolver::resolve(SpanRecorderReference::class, $extensionObjects)),
        ];
    }

    public static function configurationOf(array $extensionObjects): TracingConfiguration
    {
        $configurations = ExtensionObjectResolver::resolve(TracingConfiguration::class, $extensionObjects);
        if (count($configurations) > 1) {
            throw ConfigurationException::create('TracingConfiguration was registered ' . count($configurations) . ' times, but an application traces at one level with one configuration, so it is not clear which level to use. Keep a single #[ServiceContext] returning TracingConfiguration, with the level set by TracingConfiguration::withLevel(), and remove the others.');
        }

        return $configurations[0] ?? TracingConfiguration::createWithDefaults();
    }
}
