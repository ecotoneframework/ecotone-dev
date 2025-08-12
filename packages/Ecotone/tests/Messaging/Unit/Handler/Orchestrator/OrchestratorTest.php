<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Orchestrator;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\AuthorizationOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\OrderProcessingOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\SimpleOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\WorkflowStepHandlers;

/**
 * licence Enterprise
 * @internal
 */
class OrchestratorTest extends TestCase
{
    public function test_orchestrator_sets_routing_slip_header()
    {
        $stepHandlers = new WorkflowStepHandlers();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AuthorizationOrchestrator::class, WorkflowStepHandlers::class],
            [new AuthorizationOrchestrator(), $stepHandlers],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );

        $result = $ecotoneLite->sendDirectToChannel("start.authorization", "test-data");

        $this->assertNotNull($result);
        $this->assertEquals("email sent for: processed: validated: test-data", $result);

        $executedSteps = $stepHandlers->getExecutedSteps();
        $this->assertEquals(["validate", "process", "sendEmail"], $executedSteps);
    }

    public function test_orchestrator_requires_enterprise_license()
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Orchestrator attribute');

        EcotoneLite::bootstrapFlowTesting(
            [SimpleOrchestrator::class],
            [new SimpleOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
            // No license key provided - should throw exception
        );
    }
}
