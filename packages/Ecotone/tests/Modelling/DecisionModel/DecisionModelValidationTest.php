<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelValidationTest extends TestCase
{
    public function test_explicit_tags_naming_a_tag_missing_from_a_handled_event_is_rejected_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [ModelWithMissingTagForValidationTest::class, EventWithoutStudentTagForValidationTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_event_sourcing_handler_with_an_interface_typed_parameter_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [ModelWithInterfaceTypedHandlerForValidationTest::class, TaggedEventInterfaceForValidationTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_decision_model_with_constructor_arguments_is_rejected_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [ModelWithConstructorArgumentsForValidationTest::class, TaggedEventForValidationTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_command_handler_directly_on_a_decision_model_class_is_rejected_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [ModelWithCommandHandlerForValidationTest::class, TaggedEventForValidationTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_same_model_class_injected_twice_without_fetch_is_rejected_at_bootstrap(): void
    {
        $handler = new HandlerInjectingSameModelTwiceWithoutFetchForValidationTest();

        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, ModelForValidationTest::class, TaggedEventForValidationTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_decision_model_with_no_handled_events_is_rejected_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [ModelWithNoHandledEventsForValidationTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_non_nullable_model_param_with_a_null_tag_value_throws_naming_model_and_tag(): void
    {
        $handler = new HandlerRequiringModelForNullTagValueForValidationTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, ModelForValidationTest::class, TaggedEventForValidationTest::class, CommandWithNullTagPropertyForValidationTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectExceptionMessage('course');

        $ecotone->sendCommand(new CommandWithNullTagPropertyForValidationTest(null));
    }

    public function test_unresolvable_tag_throws_naming_the_model_and_the_tag(): void
    {
        $handler = new HandlerRequiringModelForValidationTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, ModelForValidationTest::class, TaggedEventForValidationTest::class, CommandWithoutTagPropertyForValidationTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectExceptionMessage('course');

        $ecotone->sendCommand(new CommandWithoutTagPropertyForValidationTest('irrelevant'));
    }

    public function test_model_handling_an_untagged_event_is_rejected_at_bootstrap_naming_the_event_and_both_remedies(): void
    {
        try {
            EcotoneLite::bootstrapFlowTesting(
                classesToResolve: [ModelHandlingUntaggedEventForValidationTest::class, UntaggedEventForValidationTest::class],
                configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(ModelHandlingUntaggedEventForValidationTest::class, $exception->getMessage());
            $this->assertStringContainsString(UntaggedEventForValidationTest::class, $exception->getMessage());
            $this->assertStringContainsString('#[EventTag]', $exception->getMessage());
            $this->assertStringContainsString('#[DecisionModel(tags:', $exception->getMessage());
        }
    }

    public function test_model_whose_handled_events_share_no_tag_name_is_rejected_at_bootstrap_naming_every_event_and_both_remedies(): void
    {
        try {
            EcotoneLite::bootstrapFlowTesting(
                classesToResolve: [ModelHandlingDisjointlyTaggedEventsForValidationTest::class, TaggedEventForValidationTest::class, StudentTaggedEventForValidationTest::class],
                configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(ModelHandlingDisjointlyTaggedEventsForValidationTest::class, $exception->getMessage());
            $this->assertStringContainsString(TaggedEventForValidationTest::class, $exception->getMessage());
            $this->assertStringContainsString(StudentTaggedEventForValidationTest::class, $exception->getMessage());
            $this->assertStringContainsString('#[EventTag]', $exception->getMessage());
            $this->assertStringContainsString('#[DecisionModel(tags:', $exception->getMessage());
        }
    }
}

final readonly class TaggedEventForValidationTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}

final readonly class EventWithoutStudentTagForValidationTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}

#[DecisionModel(tags: ['course', 'student'])]
final class ModelWithMissingTagForValidationTest
{
    #[EventSourcingHandler]
    public function when(EventWithoutStudentTagForValidationTest $event): void
    {
    }
}

interface TaggedEventInterfaceForValidationTest
{
}

#[DecisionModel]
final class ModelWithInterfaceTypedHandlerForValidationTest
{
    #[EventSourcingHandler]
    public function when(TaggedEventInterfaceForValidationTest $event): void
    {
    }
}

#[DecisionModel]
final class ModelWithConstructorArgumentsForValidationTest
{
    public function __construct(private readonly string $required)
    {
    }

    #[EventSourcingHandler]
    public function when(TaggedEventForValidationTest $event): void
    {
    }
}

#[DecisionModel]
final class ModelForValidationTest
{
    #[EventSourcingHandler]
    public function when(TaggedEventForValidationTest $event): void
    {
    }
}

final readonly class CommandWithoutTagPropertyForValidationTest
{
    public function __construct(
        public string $unrelatedField,
    ) {
    }
}

#[DecisionModel]
final class ModelWithCommandHandlerForValidationTest
{
    #[EventSourcingHandler]
    public function when(TaggedEventForValidationTest $event): void
    {
    }

    #[CommandHandler]
    public function handle(CommandWithoutTagPropertyForValidationTest $command): array
    {
        return [];
    }
}

final class HandlerInjectingSameModelTwiceWithoutFetchForValidationTest
{
    #[CommandHandler]
    public function handle(CommandWithoutTagPropertyForValidationTest $command, ModelForValidationTest $modelA, ModelForValidationTest $modelB): array
    {
        return [];
    }
}

#[DecisionModel]
final class ModelWithNoHandledEventsForValidationTest
{
}

final readonly class CommandWithNullTagPropertyForValidationTest
{
    public function __construct(
        public ?string $courseId,
    ) {
    }
}

final class HandlerRequiringModelForNullTagValueForValidationTest
{
    #[CommandHandler]
    public function handle(CommandWithNullTagPropertyForValidationTest $command, ModelForValidationTest $model): array
    {
        return [];
    }
}

final class HandlerRequiringModelForValidationTest
{
    #[CommandHandler]
    public function handle(CommandWithoutTagPropertyForValidationTest $command, ModelForValidationTest $model): array
    {
        return [];
    }
}

final readonly class UntaggedEventForValidationTest
{
    public function __construct(
        public string $courseId,
    ) {
    }
}

final readonly class StudentTaggedEventForValidationTest
{
    public function __construct(
        #[EventTag('student')] public string $studentId,
    ) {
    }
}

#[DecisionModel]
final class ModelHandlingUntaggedEventForValidationTest
{
    #[EventSourcingHandler]
    public function when(UntaggedEventForValidationTest $event): void
    {
    }
}

#[DecisionModel]
final class ModelHandlingDisjointlyTaggedEventsForValidationTest
{
    #[EventSourcingHandler]
    public function whenCourseTagged(TaggedEventForValidationTest $event): void
    {
    }

    #[EventSourcingHandler]
    public function whenStudentTagged(StudentTaggedEventForValidationTest $event): void
    {
    }
}
