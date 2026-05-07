<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Scheduled\ScheduledMarkerInvocationCounter;
use Test\Ecotone\Messaging\Fixture\Scheduled\ScheduledServiceWithMarker;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ScheduledModuleTest extends TestCase
{
    public function test_attributes_on_scheduled_method_trigger_pointcut_interceptors(): void
    {
        $service = new ScheduledServiceWithMarker();
        $counter = new ScheduledMarkerInvocationCounter();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [ScheduledServiceWithMarker::class, ScheduledMarkerInvocationCounter::class],
            [$service, $counter],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([])),
        );

        $ecotone->run('scheduledWithMarker', ExecutionPollingMetadata::createWithTestingSetup(1, 1));

        $this->assertSame(
            1,
            $ecotone->sendQueryWithRouting('scheduledMarker.count'),
            'Method-level attributes on a #[Scheduled] method must reach the channel adapter so attribute-pointcut interceptors fire.',
        );
    }
}
