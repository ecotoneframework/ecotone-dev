<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Orchestrator;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\AsynchronousOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\AuthorizationOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\CombinedOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\ArrayWithNonStringOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\InvalidReturnTypeOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\NoReturnTypeOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\NullableArrayOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\StringReturnTypeOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\UnionTypeOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\VoidReturnTypeOrchestrator;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\OrchestratorEndingDuringFlow;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\OrchestratorWithAsynchronousStep;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\OrchestratorWithInternalBus;
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

    public function test_throwing_exception_when_orchestrator_has_no_return_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Orchestrator method Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\NoReturnTypeOrchestrator::noReturnType must return array of strings, but returns nullable type anything');

        EcotoneLite::bootstrapFlowTesting(
            [NoReturnTypeOrchestrator::class],
            [new NoReturnTypeOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );
    }

    public function test_throwing_exception_when_orchestrator_has_union_type_with_array_return_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Orchestrator method Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\UnionTypeOrchestrator::unionType must return array of strings, but returns union type array|string');

        EcotoneLite::bootstrapFlowTesting(
            [UnionTypeOrchestrator::class],
            [new UnionTypeOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );
    }

    public function test_throwing_exception_when_orchestrator_has_nullable_array_return_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Orchestrator method Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Orchestrator\Incorrect\NullableArrayOrchestrator::nullableArray must return array of strings, but returns nullable type array');

        EcotoneLite::bootstrapFlowTesting(
            [NullableArrayOrchestrator::class],
            [new NullableArrayOrchestrator()],
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
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrchestratorEndingDuringFlow::class],
            [$service = new OrchestratorEndingDuringFlow()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );

        $ecotoneLite->sendDirectToChannel("orchestrator.ending.during.flow", "test-data");

        $this->assertEquals(["step1", "step2"], $service->getExecutedSteps());
    }

    public function test_second_orchestrator_is_step_within_the_workflow(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [CombinedOrchestrator::class],
            [$service = new CombinedOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );

        $ecotoneLite->sendDirectToChannel("orchestrator.ending.during.flow", []);

        $this->assertEquals(["stepA", "stepB", "stepA", "stepB", "stepC"], $service->getExecutedSteps());
    }

    public function test_command_bus_is_called_within_the_workflow_not_affecting_orchestrator(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrchestratorWithInternalBus::class],
            [$service = new OrchestratorWithInternalBus()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
        );

        $ecotoneLite->sendDirectToChannel("orchestrator.ending.during.flow", []);

        $this->assertEquals(["stepA", "stepB", "commandBusAction.execute", "stepC"], $service->getExecutedSteps());
    }

    public function test_asynchronous_orchestrator(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [AsynchronousOrchestrator::class],
            [$service = new AsynchronousOrchestrator()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('async'),
            ]
        );

        $ecotoneLite->sendDirectToChannel("asynchronous.workflow", []);

        $this->assertEquals([], $service->getExecutedSteps());

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertEquals(["stepA", "stepB", "stepC"], $service->getExecutedSteps());
    }

    public function test_asynchronous_step_within_orchestrator(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrchestratorWithAsynchronousStep::class],
            [$service = new OrchestratorWithAsynchronousStep()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('async'),
            ]
        );

        $ecotoneLite->sendDirectToChannel("asynchronous.workflow", []);

        $this->assertEquals(["stepA"], $service->getExecutedSteps());

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals(["stepA", "stepB", "stepC"], $service->getExecutedSteps());
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
