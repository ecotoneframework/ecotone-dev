<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelNestedHandlerTest extends TestCase
{
    public function test_a_handler_whose_nested_command_appends_to_the_same_tag_fails_with_a_message_naming_that_cause(): void
    {
        $outer = new EnrolmentHandlerForNestedTest();
        $inner = new WaitlistHandlerForNestedTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$outer::class, $inner::class, SeatCountForNestedTest::class, SeatTakenForNestedTest::class],
            containerOrAvailableServices: [$outer, $inner],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        try {
            $ecotone->sendCommand(new EnrolForNestedTest('course-1'));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            $this->assertStringContainsString('course:course-1', $exception->getMessage());
            $this->assertStringContainsString('earlier append in the same', $exception->getMessage());
        }
    }
}

final readonly class EnrolForNestedTest
{
    public function __construct(
        public string $courseId,
    ) {
    }
}

final readonly class JoinWaitlistForNestedTest
{
    public function __construct(
        public string $courseId,
    ) {
    }
}

final readonly class SeatTakenForNestedTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}

#[DecisionModel]
final class SeatCountForNestedTest
{
    public int $taken = 0;

    #[EventSourcingHandler]
    public function when(SeatTakenForNestedTest $event): void
    {
        $this->taken++;
    }
}

final class EnrolmentHandlerForNestedTest
{
    #[CommandHandler]
    public function enrol(EnrolForNestedTest $command, SeatCountForNestedTest $seats, CommandBus $commandBus): array
    {
        $commandBus->send(new JoinWaitlistForNestedTest($command->courseId));

        return [new SeatTakenForNestedTest($command->courseId)];
    }
}

final class WaitlistHandlerForNestedTest
{
    #[CommandHandler]
    public function join(JoinWaitlistForNestedTest $command, SeatCountForNestedTest $seats): array
    {
        return [new SeatTakenForNestedTest($command->courseId)];
    }
}
