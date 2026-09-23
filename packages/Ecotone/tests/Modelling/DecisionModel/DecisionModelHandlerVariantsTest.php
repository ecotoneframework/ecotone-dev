<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class DecisionModelHandlerVariantsTest extends TestCase
{
    public function test_metadata_is_propagated_from_the_command_to_the_appended_event(): void
    {
        $counter = new InMemoryCounterServiceForVariantsTest();
        $handler = new HandlerWithReferenceForVariantsTest($counter);

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [HandlerWithReferenceForVariantsTest::class, CourseForVariantsTest::class, ItemAddedForVariantsTest::class],
            containerOrAvailableServices: [$handler, $counter],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('addItem', new AddItemForVariantsTest('course-1'), metadata: ['correlationHeader' => 'abc']);

        $events = $ecotone->getEventStreamEvents('ecotone_event_stream');
        $this->assertCount(1, $events);
        $this->assertSame('abc', $events[0]->getMetadata()['correlationHeader']);
    }

    public function test_reference_service_is_injected_alongside_a_decision_model(): void
    {
        $counter = new InMemoryCounterServiceForVariantsTest();
        $handler = new HandlerWithReferenceForVariantsTest($counter);

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [HandlerWithReferenceForVariantsTest::class, CourseForVariantsTest::class, ItemAddedForVariantsTest::class],
            containerOrAvailableServices: [$handler, $counter],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('addItem', new AddItemForVariantsTest('course-1'));

        $this->assertSame(1, $counter->count);
    }

    public function test_asynchronous_handler_injecting_a_model_runs_via_run(): void
    {
        $handler = new AsyncHandlerForVariantsTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [AsyncHandlerForVariantsTest::class, CourseForVariantsTest::class, ItemAddedForVariantsTest::class],
            containerOrAvailableServices: [$handler],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('addItemAsync', new AddItemForVariantsTest('course-1'));

        $this->assertCount(0, $ecotone->getEventStreamEvents('ecotone_event_stream'));

        $ecotone->run('async');

        $this->assertCount(1, $ecotone->getEventStreamEvents('ecotone_event_stream'));
    }

    public function test_query_handler_with_a_model_replies_and_appends_nothing(): void
    {
        $handler = new QueryHandlerForVariantsTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [QueryHandlerForVariantsTest::class, CourseForVariantsTest::class, ItemAddedForVariantsTest::class, GetItemCountForVariantsTest::class],
            containerOrAvailableServices: [$handler],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new ItemAddedForVariantsTest('course-1'), new ItemAddedForVariantsTest('course-1')]);

        $itemCount = $ecotone->sendQueryWithRouting('getItemCount', new GetItemCountForVariantsTest('course-1'));

        $this->assertSame(2, $itemCount);
        $this->assertCount(2, $ecotone->getEventStreamEvents('ecotone_event_stream'));
    }

    public function test_output_channel_name_is_honoured_on_a_model_injecting_handler(): void
    {
        $handler = new OutputChannelHandlerForVariantsTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [OutputChannelHandlerForVariantsTest::class, CourseForVariantsTest::class, ItemAddedForVariantsTest::class],
            containerOrAvailableServices: [$handler],
            configuration: \Ecotone\Api\ExtensionObject\ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    \Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder::createQueueChannel('forwardedChannel'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('addItem', new AddItemForVariantsTest('course-1'));

        $this->assertCount(1, $ecotone->getEventStreamEvents('ecotone_event_stream'));

        $forwardedMessage = $ecotone->receiveMessageFrom('forwardedChannel');
        $this->assertNotNull($forwardedMessage);
    }
}

final readonly class AddItemForVariantsTest
{
    public function __construct(
        public string $courseId,
    ) {
    }
}

final readonly class ItemAddedForVariantsTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}

#[DecisionModel]
final class CourseForVariantsTest
{
    private int $items = 0;

    #[EventSourcingHandler]
    public function itemAdded(ItemAddedForVariantsTest $event): void
    {
        $this->items++;
    }

    public function items(): int
    {
        return $this->items;
    }
}

final class InMemoryCounterServiceForVariantsTest
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
}

final class HandlerWithReferenceForVariantsTest
{
    public function __construct(
        private readonly InMemoryCounterServiceForVariantsTest $counterService,
    ) {
    }

    #[CommandHandler('addItem')]
    public function addItem(
        AddItemForVariantsTest $command,
        CourseForVariantsTest $course,
        #[Reference] InMemoryCounterServiceForVariantsTest $counterService,
        #[Header('correlationHeader')] ?string $correlationHeader = null,
    ): array {
        $counterService->increment();

        return [new ItemAddedForVariantsTest($command->courseId)];
    }
}

final class AsyncHandlerForVariantsTest
{
    #[Asynchronous('async')]
    #[CommandHandler('addItemAsync', endpointId: 'addItemAsyncEndpoint')]
    public function addItem(AddItemForVariantsTest $command, CourseForVariantsTest $course): array
    {
        return [new ItemAddedForVariantsTest($command->courseId)];
    }
}

final readonly class GetItemCountForVariantsTest
{
    public function __construct(
        public string $courseId,
    ) {
    }
}

final class QueryHandlerForVariantsTest
{
    #[QueryHandler('getItemCount')]
    public function getItemCount(GetItemCountForVariantsTest $query, CourseForVariantsTest $course): int
    {
        return $course->items();
    }
}

final class OutputChannelHandlerForVariantsTest
{
    #[CommandHandler('addItem', outputChannelName: 'forwardedChannel')]
    public function addItem(AddItemForVariantsTest $command, CourseForVariantsTest $course): array
    {
        return [new ItemAddedForVariantsTest($command->courseId)];
    }
}
