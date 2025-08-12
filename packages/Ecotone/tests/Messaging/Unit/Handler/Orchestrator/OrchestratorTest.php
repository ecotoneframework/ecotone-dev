<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Orchestrator;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\AuthorizationOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\ArrayWithNonStringOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\InvalidReturnTypeOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\StringReturnTypeOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\VoidReturnTypeOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\SimpleOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\WorkflowStepHandlers;

/**
 * licence Enterprise
 * @internal
 */
class OrchestratorTest extends TestCase
{
    public function test_orchestrator_passes_message(): void
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

    public function test_orchestrator_returns_empty_array_no_routing_happens(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [SimpleOrchestrator::class],
            [new SimpleOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );

        $this->assertSame('test-data', $ecotoneLite->sendDirectToChannel("empty.workflow", "test-data"));
    }

    public function test_orchestrator_with_single_step(): void
    {
        $stepHandlers = new WorkflowStepHandlers();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [SimpleOrchestrator::class, WorkflowStepHandlers::class],
            [new SimpleOrchestrator(), $stepHandlers],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );

        $this->assertSame('validated: test-data', $ecotoneLite->sendDirectToChannel("single.step", "test-data"));
    }

    public function test_throwing_exception_with_single_step_as_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Orchestrator method Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\StringReturnTypeOrchestrator::singleStepAsString must return array of strings, but returns string');

        EcotoneLite::bootstrapFlowTesting(
            [StringReturnTypeOrchestrator::class],
            [new StringReturnTypeOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );
    }

    public function test_throwing_exception_when_orchestrator_returns_non_array_or_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Orchestrator method Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\InvalidReturnTypeOrchestrator::invalidReturnType must return array of strings, but returns stdClass');

        EcotoneLite::bootstrapFlowTesting(
            [InvalidReturnTypeOrchestrator::class],
            [new InvalidReturnTypeOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );
    }

    public function test_throwing_exception_with_array_returned_of_non_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Orchestrator returned array must contain only strings');

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ArrayWithNonStringOrchestrator::class],
            [new ArrayWithNonStringOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );

        // This should fail at runtime when the orchestrator executes and returns non-string array
        $ecotoneLite->sendDirectToChannel("array.with.non.string", "test-data");
    }

    public function test_throwing_exception_with_void_return_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Orchestrator method Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\VoidReturnTypeOrchestrator::voidReturnType must return array of strings, but returns void');

        EcotoneLite::bootstrapFlowTesting(
            [VoidReturnTypeOrchestrator::class],
            [new VoidReturnTypeOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );
    }

    public function test_workflow_is_ending_on_null_returned_within_step(): void
    {

    }

    public function test_second_orchestrator_is_step_within_the_workflow(): void
    {

    }

    public function test_command_bus_is_called_within_the_workflow_not_affecting_orchestrator(): void
    {

    }


    public function test_orchestrator_requires_enterprise_license(): void
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
