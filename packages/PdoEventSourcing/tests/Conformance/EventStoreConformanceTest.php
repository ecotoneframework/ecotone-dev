<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Conformance;

use Closure;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\EventSourcing\FieldType;
use Ecotone\Api\EventSourcing\MetadataMatcher;
use Ecotone\Api\EventSourcing\Operator;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class EventStoreConformanceTest extends EventSourcingMessagingTestCase
{
    private const KNOWN_DIVERGENCES = [
        'E6' => [
            'sides' => 'load() of a stream that was never created — in-memory returns no events, DBAL refuses with the missing-table instructions. Intended: a DBAL stream lives in a table that ecotone:migration:database:setup, or the test bootstrap, creates before anything reads it, so a missing one is a deployment mistake to name; an in-memory store has no tables, and a stream exists from its first append',
            'cases' => ['test_loading_a_stream_that_was_never_created_is_refused' => ['in-memory', 'in-memory-without-event-sourcing-module']],
        ],
        'E7' => [
            'sides' => 'appendTo() with no events on a missing stream — in-memory creates the stream, DBAL leaves it missing',
            'cases' => ['test_appending_no_events_to_a_missing_stream_leaves_it_missing' => ['in-memory', 'in-memory-without-event-sourcing-module']],
        ],
        'E11' => [
            'sides' => 'appendTo() on a stream no setup created - every DBAL engine refuses with the missing-table ConfigurationException, as no table is created while a message is handled; in-memory has no tables and materialises the stream on its first append',
            'cases' => ['test_appending_events_to_a_stream_no_setup_created_is_refused' => ['in-memory', 'in-memory-without-event-sourcing-module']],
        ],
    ];

    private const STREAM = 'ecotone_event_stream';
    public const SECOND_STREAM = 'conformance_second_stream';
    private const UNDECLARED_STREAM = 'conformance_undeclared_stream';

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'in-memory-without-event-sourcing-module' => ['in-memory-without-event-sourcing-module'];
        yield 'dbal' => ['dbal'];
    }

    public function test_every_known_divergence_names_a_case_of_this_suite(): void
    {
        foreach (self::KNOWN_DIVERGENCES as $knownDivergence) {
            foreach (array_keys($knownDivergence['cases']) as $case) {
                self::assertContains($case, get_class_methods($this));
            }
        }
    }

    #[DataProvider('implementations')]
    public function test_appended_events_load_in_append_order_with_their_payload_and_metadata(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                Event::create(new TicketOpenedForEventStoreConformance('t-1', 'Printer jam'), ['source' => 'web']),
                Event::create(new TicketClosedForEventStoreConformance('t-1'), ['source' => 'phone']),
            ]);

            $events = $eventStore->load(self::STREAM);

            self::assertEquals(
                [new TicketOpenedForEventStoreConformance('t-1', 'Printer jam'), new TicketClosedForEventStoreConformance('t-1')],
                $this->payloadsOf($events),
            );
            self::assertSame(['web', 'phone'], [$events[0]->metadata['source'], $events[1]->metadata['source']]);
            self::assertSame([TicketOpenedForEventStoreConformance::class, TicketClosedForEventStoreConformance::class], [$events[0]->eventName, $events[1]->eventName]);
        });
    }

    #[DataProvider('implementations')]
    public function test_events_appended_in_separate_calls_load_in_the_order_they_were_appended(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [new TicketOpenedForEventStoreConformance('t-2', 'Second')]);
            $eventStore->appendTo(self::STREAM, [new TicketOpenedForEventStoreConformance('t-1', 'First')]);
            $eventStore->appendTo(self::STREAM, [new TicketClosedForEventStoreConformance('t-2')]);

            self::assertEquals(
                [new TicketOpenedForEventStoreConformance('t-2', 'Second'), new TicketOpenedForEventStoreConformance('t-1', 'First'), new TicketClosedForEventStoreConformance('t-2')],
                $this->payloadsOf($eventStore->load(self::STREAM)),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_loading_from_a_number_skips_the_events_before_it(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->threeTickets());

            self::assertEquals(['t-2', 't-3'], $this->ticketIdsOf($eventStore->load(self::STREAM, fromNumber: 2)));
        });
    }

    #[DataProvider('implementations')]
    public function test_loading_with_a_count_returns_at_most_that_many_events(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->threeTickets());

            self::assertEquals(['t-1', 't-2'], $this->ticketIdsOf($eventStore->load(self::STREAM, count: 2)));
            self::assertEquals(['t-2'], $this->ticketIdsOf($eventStore->load(self::STREAM, fromNumber: 2, count: 1)));
            self::assertEquals(['t-1', 't-2', 't-3'], $this->ticketIdsOf($eventStore->load(self::STREAM, count: 10)));
        });
    }

    #[DataProvider('implementations')]
    public function test_loading_from_a_number_past_the_last_event_returns_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->threeTickets());

            self::assertSame([], $this->ticketIdsOf($eventStore->load(self::STREAM, fromNumber: 4)));
        });
    }

    #[DataProvider('implementations')]
    public function test_loading_from_a_number_below_one_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->threeTickets());

            try {
                $eventStore->load(self::STREAM, fromNumber: 0);
                self::fail('Expected a number below one to be refused');
            } catch (InvalidArgumentException) {
            }

            self::assertCount(3, $eventStore->load(self::STREAM));
        });
    }

    #[DataProvider('implementations')]
    public function test_loading_a_count_of_zero_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->twoTicketsLifecycle());

            try {
                $eventStore->load(self::STREAM, count: 0);
                self::fail('Expected a count of zero to be refused');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('count must be >= 1 or null, got 0: it caps how many events are loaded, so 0 would load none. Pass null to load every event that matches.', $exception->getMessage());
            }

            try {
                $eventStore->loadAggregateEvents(self::STREAM, 'ticket', 't-1', count: 0);
                self::fail('Expected a count of zero to be refused when loading aggregate events');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('count must be >= 1 or null, got 0: it caps how many events are loaded, so 0 would load none. Pass null to load every event that matches.', $exception->getMessage());
            }

            self::assertCount(3, $eventStore->load(self::STREAM));
        });
    }

    #[DataProvider('implementations')]
    public function test_an_empty_metadata_matcher_returns_every_event(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->threeTickets());

            self::assertEquals(['t-1', 't-2', 't-3'], $this->ticketIdsOf($eventStore->load(self::STREAM, metadataMatcher: new MetadataMatcher())));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_metadata_matcher_returns_only_the_events_matching_it(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                Event::create(new TicketOpenedForEventStoreConformance('t-1', 'One'), ['channel' => 'web']),
                Event::create(new TicketOpenedForEventStoreConformance('t-2', 'Two'), ['channel' => 'phone']),
                Event::create(new TicketOpenedForEventStoreConformance('t-3', 'Three'), ['channel' => 'web']),
            ]);

            self::assertEquals(['t-1', 't-3'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::EQUALS, 'web'),
            )));
            self::assertEquals(['t-2'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::NOT_EQUALS, 'web'),
            )));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_positive_metadata_match_never_returns_an_event_that_lacks_the_matched_key(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                Event::create(new TicketOpenedForEventStoreConformance('t-1', 'One'), ['channel' => 'web']),
                new TicketOpenedForEventStoreConformance('t-2', 'Two'),
            ]);

            self::assertEquals(['t-1'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::EQUALS, 'web'),
            )));
            self::assertEquals(['t-1'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::IN, ['web', 'phone']),
            )));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_negative_metadata_match_keeps_an_event_that_lacks_the_matched_key(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                Event::create(new TicketOpenedForEventStoreConformance('t-1', 'One'), ['channel' => 'web']),
                new TicketOpenedForEventStoreConformance('t-2', 'Two'),
                Event::create(new TicketOpenedForEventStoreConformance('t-3', 'Three'), ['channel' => 'phone']),
            ]);

            self::assertEquals(['t-2', 't-3'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::NOT_EQUALS, 'web'),
            )));
            self::assertEquals(['t-2', 't-3'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::NOT_IN, ['web']),
            )));
            self::assertEquals(['t-2'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::NOT_IN, ['web', 'phone']),
            )));
            self::assertEquals(['t-2'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::NOT_EQUALS, 'phone')->withMetadataMatch('channel', Operator::NOT_EQUALS, 'web'),
            )));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_metadata_matcher_with_an_empty_in_list_matches_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                Event::create(new TicketOpenedForEventStoreConformance('t-1', 'One'), ['channel' => 'web']),
            ]);

            self::assertSame([], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::IN, []),
            )));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_metadata_matcher_with_an_empty_not_in_list_excludes_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                Event::create(new TicketOpenedForEventStoreConformance('t-1', 'One'), ['channel' => 'web']),
                new TicketOpenedForEventStoreConformance('t-2', 'Two'),
            ]);

            self::assertEquals(['t-1', 't-2'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('channel', Operator::NOT_IN, []),
            )));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_metadata_value_recorded_as_an_integer_is_matched_by_its_string_form(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                Event::create(new TicketOpenedForEventStoreConformance('t-1', 'One'), ['priority' => 1]),
                Event::create(new TicketOpenedForEventStoreConformance('t-2', 'Two'), ['priority' => 2]),
            ]);

            self::assertEquals(['t-2'], $this->ticketIdsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('priority', Operator::EQUALS, '2'),
            )));
        });
    }

    #[DataProvider('implementations')]
    public function test_events_are_matched_by_their_name(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                new TicketOpenedForEventStoreConformance('t-1', 'One'),
                new TicketClosedForEventStoreConformance('t-1'),
            ]);

            self::assertEquals([new TicketClosedForEventStoreConformance('t-1')], $this->payloadsOf($eventStore->load(
                self::STREAM,
                metadataMatcher: (new MetadataMatcher())->withMetadataMatch('event_name', Operator::IN, [TicketClosedForEventStoreConformance::class], FieldType::MESSAGE_PROPERTY),
            )));
        });
    }

    #[DataProvider('implementations')]
    public function test_loading_without_deserialization_returns_the_payload_as_recorded_data(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [new TicketOpenedForEventStoreConformance('t-1', 'One')]);

            $events = $eventStore->load(self::STREAM, deserialize: false);

            self::assertEquals(['ticketId' => 't-1', 'title' => 'One'], $events[0]->getPayload());
            self::assertSame(TicketOpenedForEventStoreConformance::class, $events[0]->eventName);
        });
    }

    #[DataProvider('implementations')]
    public function test_streams_do_not_see_each_others_events(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [new TicketOpenedForEventStoreConformance('t-1', 'One')]);
            $eventStore->appendTo(self::SECOND_STREAM, [new TicketOpenedForEventStoreConformance('t-2', 'Two')]);

            self::assertEquals(['t-1'], $this->ticketIdsOf($eventStore->load(self::STREAM)));
            self::assertEquals(['t-2'], $this->ticketIdsOf($eventStore->load(self::SECOND_STREAM)));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_stream_that_was_never_created_does_not_exist(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            self::assertFalse($eventStore->hasStream(self::UNDECLARED_STREAM));
        });
    }

    #[DataProvider('implementations')]
    public function test_loading_a_stream_that_was_never_created_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            try {
                $eventStore->load(self::UNDECLARED_STREAM);
                self::fail('Expected loading a stream that was never created to be refused');
            } catch (ConfigurationException) {
            }

            self::assertFalse($eventStore->hasStream(self::UNDECLARED_STREAM));
        });
    }

    #[DataProvider('implementations')]
    public function test_appending_events_to_a_stream_no_setup_created_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            try {
                $eventStore->appendTo(self::UNDECLARED_STREAM, [new TicketOpenedForEventStoreConformance('t-1', 'One')]);
                self::fail('Expected appending to a stream no setup created to be refused');
            } catch (ConfigurationException) {
            }

            self::assertFalse($eventStore->hasStream(self::UNDECLARED_STREAM));
        });
    }

    #[DataProvider('implementations')]
    public function test_appending_no_events_to_a_missing_stream_leaves_it_missing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::UNDECLARED_STREAM, []);

            self::assertFalse($eventStore->hasStream(self::UNDECLARED_STREAM));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_deleted_stream_no_longer_exists(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::SECOND_STREAM, [new TicketOpenedForEventStoreConformance('t-1', 'One')]);

            $eventStore->delete(self::SECOND_STREAM);

            self::assertFalse($eventStore->hasStream(self::SECOND_STREAM));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_deleted_stream_set_up_again_starts_afresh(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore, Closure $setUpTables): void {
            $eventStore->appendTo(self::SECOND_STREAM, $this->threeTickets());
            $eventStore->delete(self::SECOND_STREAM);

            $setUpTables();
            $eventStore->appendTo(self::SECOND_STREAM, [new TicketOpenedForEventStoreConformance('t-4', 'Four'), new TicketOpenedForEventStoreConformance('t-5', 'Five')]);

            self::assertEquals(['t-4', 't-5'], $this->ticketIdsOf($eventStore->load(self::SECOND_STREAM)));
            self::assertEquals(['t-5'], $this->ticketIdsOf($eventStore->load(self::SECOND_STREAM, fromNumber: 2)));
        });
    }

    #[DataProvider('implementations')]
    public function test_deleting_a_stream_that_never_existed_changes_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [new TicketOpenedForEventStoreConformance('t-1', 'One')]);

            $eventStore->delete(self::UNDECLARED_STREAM);

            self::assertFalse($eventStore->hasStream(self::UNDECLARED_STREAM));
            self::assertEquals(['t-1'], $this->ticketIdsOf($eventStore->load(self::STREAM)));
        });
    }

    #[DataProvider('implementations')]
    public function test_aggregate_events_load_by_aggregate_type_and_id(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->twoTicketsLifecycle());

            self::assertEquals(
                [new TicketOpenedForEventStoreConformance('t-1', 'One'), new TicketClosedForEventStoreConformance('t-1')],
                $this->payloadsOf($eventStore->loadAggregateEvents(self::STREAM, 'ticket', 't-1')),
            );
            self::assertEquals(
                [new TicketOpenedForEventStoreConformance('t-2', 'Two')],
                $this->payloadsOf($eventStore->loadAggregateEvents(self::STREAM, null, 't-2')),
            );
            self::assertSame([], $this->payloadsOf($eventStore->loadAggregateEvents(self::STREAM, 'invoice', 't-1')));
        });
    }

    #[DataProvider('implementations')]
    public function test_aggregate_events_load_from_a_version_and_up_to_a_count(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                $this->ticketEvent(new TicketOpenedForEventStoreConformance('t-1', 'One'), 't-1', 1),
                $this->ticketEvent(new TicketRenamedForEventStoreConformance('t-1', 'One, renamed'), 't-1', 2),
                $this->ticketEvent(new TicketClosedForEventStoreConformance('t-1'), 't-1', 3),
            ]);

            self::assertEquals(
                [new TicketRenamedForEventStoreConformance('t-1', 'One, renamed'), new TicketClosedForEventStoreConformance('t-1')],
                $this->payloadsOf($eventStore->loadAggregateEvents(self::STREAM, 'ticket', 't-1', fromVersion: 2)),
            );
            self::assertEquals(
                [new TicketOpenedForEventStoreConformance('t-1', 'One'), new TicketRenamedForEventStoreConformance('t-1', 'One, renamed')],
                $this->payloadsOf($eventStore->loadAggregateEvents(self::STREAM, 'ticket', 't-1', count: 2)),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_aggregate_events_filtered_by_event_names_return_only_those_events(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->twoTicketsLifecycle());

            self::assertEquals(
                [new TicketClosedForEventStoreConformance('t-1')],
                $this->payloadsOf($eventStore->loadAggregateEvents(self::STREAM, 'ticket', 't-1', eventNames: [TicketClosedForEventStoreConformance::class])),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_aggregate_events_filtered_by_an_empty_event_name_list_return_every_event(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->twoTicketsLifecycle());

            self::assertCount(2, $eventStore->loadAggregateEvents(self::STREAM, 'ticket', 't-1', eventNames: []));
        });
    }

    #[DataProvider('implementations')]
    public function test_aggregate_events_of_an_unknown_aggregate_load_as_empty(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, $this->twoTicketsLifecycle());

            self::assertSame([], $this->payloadsOf($eventStore->loadAggregateEvents(self::STREAM, 'ticket', 't-404')));
        });
    }

    #[DataProvider('implementations')]
    public function test_an_aggregate_recorded_with_an_integer_id_loads_by_its_string_id(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                Event::create(new TicketOpenedForEventStoreConformance('42', 'Integer id'), [
                    MessageHeaders::EVENT_AGGREGATE_TYPE => 'ticket',
                    MessageHeaders::EVENT_AGGREGATE_ID => 42,
                    MessageHeaders::EVENT_AGGREGATE_VERSION => 1,
                ]),
            ]);

            self::assertEquals(['42'], $this->ticketIdsOf($eventStore->loadAggregateEvents(self::STREAM, 'ticket', '42')));
        });
    }

    #[DataProvider('implementations')]
    public function test_appending_under_the_current_aggregate_version_succeeds(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [$this->ticketEvent(new TicketOpenedForEventStoreConformance('t-1', 'One'), 't-1', 1)]);

            $eventStore->appendTo(
                self::STREAM,
                [$this->ticketEvent(new TicketClosedForEventStoreConformance('t-1'), 't-1', 2)],
                AppendCondition::forAggregate('ticket', 't-1', 1),
            );

            self::assertCount(2, $eventStore->loadAggregateEvents(self::STREAM, 'ticket', 't-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_appending_an_aggregate_version_that_was_already_recorded_is_rejected_and_appends_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [$this->ticketEvent(new TicketOpenedForEventStoreConformance('t-1', 'One'), 't-1', 1)]);

            try {
                $eventStore->appendTo(self::STREAM, [
                    $this->ticketEvent(new TicketRenamedForEventStoreConformance('t-1', 'Renamed'), 't-1', 2),
                    $this->ticketEvent(new TicketClosedForEventStoreConformance('t-1'), 't-1', 1),
                ]);
                self::fail('Expected the recorded aggregate version to be rejected');
            } catch (ConcurrencyException) {
            }

            self::assertEquals([new TicketOpenedForEventStoreConformance('t-1', 'One')], $this->payloadsOf($eventStore->load(self::STREAM)));
        });
    }

    #[DataProvider('implementations')]
    public function test_appending_under_a_stale_aggregate_version_is_rejected(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                $this->ticketEvent(new TicketOpenedForEventStoreConformance('t-1', 'One'), 't-1', 1),
                $this->ticketEvent(new TicketRenamedForEventStoreConformance('t-1', 'Renamed'), 't-1', 2),
            ]);

            try {
                $eventStore->appendTo(
                    self::STREAM,
                    [$this->ticketEvent(new TicketClosedForEventStoreConformance('t-1'), 't-1', 3)],
                    AppendCondition::forAggregate('ticket', 't-1', 1),
                );
                self::fail('Expected the stale aggregate version to be rejected');
            } catch (ConcurrencyException) {
            }

            self::assertCount(2, $eventStore->load(self::STREAM));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_stale_aggregate_version_names_both_versions_and_the_way_out(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventStore->appendTo(self::STREAM, [
                $this->ticketEvent(new TicketOpenedForEventStoreConformance('t-1', 'One'), 't-1', 1),
                $this->ticketEvent(new TicketRenamedForEventStoreConformance('t-1', 'Renamed'), 't-1', 2),
            ]);

            try {
                $eventStore->appendTo(
                    self::STREAM,
                    [$this->ticketEvent(new TicketClosedForEventStoreConformance('t-1'), 't-1', 3)],
                    AppendCondition::forAggregate('ticket', 't-1', 1),
                );
                self::fail('Expected the stale aggregate version to be rejected');
            } catch (ConcurrencyException $exception) {
                self::assertStringContainsString('Aggregate ticket t-1 was loaded at version 1, but it is at version 2 now', $exception->getMessage());
                self::assertStringContainsString('Load it again and retry', $exception->getMessage());
                self::assertStringContainsString('InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3, [ConcurrencyException::class])', $exception->getMessage());
            }
        });
    }

    #[DataProvider('implementations')]
    public function test_appending_an_event_id_that_was_already_recorded_is_rejected(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $eventId = '8c6e0ee5-0cf3-4e57-8f40-5b8e0a3b1d11';
            $eventStore->appendTo(self::STREAM, [Event::create(new TicketOpenedForEventStoreConformance('t-1', 'One'), [MessageHeaders::MESSAGE_ID => $eventId])]);

            try {
                $eventStore->appendTo(self::STREAM, [Event::create(new TicketOpenedForEventStoreConformance('t-2', 'Two'), [MessageHeaders::MESSAGE_ID => $eventId])]);
                self::fail('Expected the recorded event id to be rejected');
            } catch (ConcurrencyException) {
            }

            self::assertEquals(['t-1'], $this->ticketIdsOf($eventStore->load(self::STREAM)));
        });
    }

    private function conformanceCase(string $implementation, Closure $case): void
    {
        $target = $this->targetOf($implementation);
        $knownDivergence = $this->knownDivergenceOf($this->name(), $target);
        if ($knownDivergence === null) {
            $case(...$this->bootstrap($implementation));

            return;
        }

        try {
            $case(...$this->bootstrap($implementation));
        } catch (Throwable $divergence) {
            self::markTestSkipped("Known divergence {$knownDivergence['id']} on {$target}: {$knownDivergence['sides']}. This run: " . strtok($divergence->getMessage(), "\n"));
        }

        self::fail(
            "Known divergence {$knownDivergence['id']} is recorded for '{$knownDivergence['selector']}' on {$this->name()}, but the case now passes on {$target}. "
            . "Remove '{$knownDivergence['selector']}' from {$knownDivergence['id']} in KNOWN_DIVERGENCES, so the case runs there and a new divergence fails the suite again."
        );
    }

    /**
     * @return array{id: string, selector: string, sides: string}|null
     */
    private function knownDivergenceOf(string $case, string $target): ?array
    {
        foreach (self::KNOWN_DIVERGENCES as $id => $knownDivergence) {
            foreach ($knownDivergence['cases'][$case] ?? [] as $selector) {
                if ($selector === $target || str_starts_with($target, $selector . ':')) {
                    return ['id' => $id, 'selector' => $selector, 'sides' => $knownDivergence['sides']];
                }
            }
        }

        return null;
    }

    private function targetOf(string $implementation): string
    {
        if ($implementation !== 'dbal') {
            return $implementation;
        }

        $platform = $this->getConnection()->getDatabasePlatform();

        return 'dbal:' . match (true) {
            $platform instanceof MariaDBPlatform => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform => 'pgsql',
            $platform instanceof SQLitePlatform => 'sqlite',
        };
    }

    /**
     * @return array{0: EventStore, 1: Closure(): void}
     */
    private function bootstrap(string $implementation): array
    {
        $converter = new class () {
            #[Converter]
            public function fromOpened(TicketOpenedForEventStoreConformance $event): array
            {
                return ['ticketId' => $event->ticketId, 'title' => $event->title];
            }

            #[Converter]
            public function toOpened(array $event): TicketOpenedForEventStoreConformance
            {
                return new TicketOpenedForEventStoreConformance($event['ticketId'], $event['title']);
            }

            #[Converter]
            public function fromRenamed(TicketRenamedForEventStoreConformance $event): array
            {
                return ['ticketId' => $event->ticketId, 'title' => $event->title];
            }

            #[Converter]
            public function toRenamed(array $event): TicketRenamedForEventStoreConformance
            {
                return new TicketRenamedForEventStoreConformance($event['ticketId'], $event['title']);
            }

            #[Converter]
            public function fromClosed(TicketClosedForEventStoreConformance $event): array
            {
                return ['ticketId' => $event->ticketId];
            }

            #[Converter]
            public function toClosed(array $event): TicketClosedForEventStoreConformance
            {
                return new TicketClosedForEventStoreConformance($event['ticketId']);
            }
        };
        $secondStream = new #[Stream(EventStoreConformanceTest::SECOND_STREAM)] class () {
        };

        if ($implementation === 'in-memory-without-event-sourcing-module') {
            return [
                EcotoneLite::bootstrapFlowTesting(
                    classesToResolve: [$converter::class, $secondStream::class],
                    containerOrAvailableServices: [$converter],
                )->getGateway(EventStore::class),
                function (): void {
                },
            ];
        }

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [$converter::class, $secondStream::class],
            containerOrAvailableServices: [$converter, DbalConnectionFactory::class => self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    match ($implementation) {
                        'in-memory' => EventSourcingConfiguration::createInMemory(),
                        'dbal' => EventSourcingConfiguration::createWithDefaults(),
                    },
                ]),
            runForProductionEventStore: true,
        );

        return [$ecotone->getGateway(EventStore::class), fn () => $ecotone->setupDatabaseTables()];
    }

    private function ticketEvent(object $payload, string $ticketId, int $version): Event
    {
        return Event::create($payload, [
            MessageHeaders::EVENT_AGGREGATE_TYPE => 'ticket',
            MessageHeaders::EVENT_AGGREGATE_ID => $ticketId,
            MessageHeaders::EVENT_AGGREGATE_VERSION => $version,
        ]);
    }

    private function threeTickets(): array
    {
        return [
            new TicketOpenedForEventStoreConformance('t-1', 'One'),
            new TicketOpenedForEventStoreConformance('t-2', 'Two'),
            new TicketOpenedForEventStoreConformance('t-3', 'Three'),
        ];
    }

    private function twoTicketsLifecycle(): array
    {
        return [
            $this->ticketEvent(new TicketOpenedForEventStoreConformance('t-1', 'One'), 't-1', 1),
            $this->ticketEvent(new TicketOpenedForEventStoreConformance('t-2', 'Two'), 't-2', 1),
            $this->ticketEvent(new TicketClosedForEventStoreConformance('t-1'), 't-1', 2),
        ];
    }

    private function payloadsOf(iterable $events): array
    {
        $payloads = [];
        foreach ($events as $event) {
            $payloads[] = $event->getPayload();
        }

        return $payloads;
    }

    private function ticketIdsOf(iterable $events): array
    {
        return array_map(fn (object $payload): string => $payload->ticketId, $this->payloadsOf($events));
    }
}

final readonly class TicketOpenedForEventStoreConformance
{
    public function __construct(public string $ticketId, public string $title)
    {
    }
}

final readonly class TicketRenamedForEventStoreConformance
{
    public function __construct(public string $ticketId, public string $title)
    {
    }
}

final readonly class TicketClosedForEventStoreConformance
{
    public function __construct(public string $ticketId)
    {
    }
}
