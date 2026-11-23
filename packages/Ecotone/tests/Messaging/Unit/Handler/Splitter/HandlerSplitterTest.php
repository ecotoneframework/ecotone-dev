<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Splitter;

use ArrayIterator;
use DateTimeImmutable;
use Ecotone\Api\Attribute\After;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Before;
use Ecotone\Api\Attribute\ChangingHeaders;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Modelling\WithEvents;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\TracingConfiguration;
use Generator;
use PHPUnit\Framework\TestCase;
use stdClass;
use Traversable;

/**
 * licence Apache-2.0
 * @internal
 */
final class HandlerSplitterTest extends TestCase
{
    private const ENTERPRISE_LICENCE = 'eyJzaWduYXR1cmUiOiJxRmtrSDdCQkxzdUlTRzExZmVXeHVKVTY3bGZScmFSTFBpb0VUaGdoZTAyYWRjajlVRkNWWXhvaGl4YXhoaEFlNzU5aExOT0R5NXo1T2N2eFppUGQyemRRblYrb1dNZjFwM2lqbFN4cUg1MDhVV1wvOG90TllkM28wMVRZRmdMSjU4RXJzYThLcUJib0Z1MmhaZW9lczQ3elM2VHR5TzFGV2pOeWxlbG1aRWNLUHFydFhzOTNcL1YxY0wwWVg4UFBOZDl6bVRPOWxyc3UzM3F1anBXVlB4eVRMTW4yK3lPc3Jxa2pJZDArSU1LSWl0dG1IZGlBcml6NnZIXC93RnFKTU5Od2JKWnFsUXRNa3pQc3pvRlFseVgyZnNPTG5CTWNCajA5S0VnNjhVcG14OVwvUnBHZXBNV2UzcThYeFZMa3laOEtXdndEN2JBZDZPSXVoejlxRThyamVQVVVsbUxybm0wd3ZFYjV6UVZEbU5OY21uZnNkMEYyazJhSldySzc5VXlEWVY3UFNvVkZiM0diV0VNN2p1STVFV2w5cTVaWXJiOGxFcmxTTmNpYmQwbCttMWkzUWk1TnZhU1RscHBTV1BGS0g4c2x5dDhKRzV4YXZqQlJFU3BJWGVDNE9VREdoZDRJNEhaaEpDXC9Wcm9Nb0FRNzBMaXRrUHcxWkdWQ1dqTURucHdNdWRyWThpMVQwak5MRytXYlpYckpvRHZxd0x4SENKdU9EWnhuYVNISkVVSVFndGViUXZPRURIKzBjN0o0VVJMWkV2NnU2d2tcL1F6TzlPVnVVS1BubDkzK1dVam8xVDZqOEJ0MEtSeHhsMmtBY2pQcE9wWTg5Q25BQlBtckF4THJhYkxXeThpZmxVQ0cwUjNmZzB4UnpiMjlMSENvUW95b2NmRStzYjhFYz0iLCJkYXRhIjp7ImVtYWlsIjoiam9obnlAd3AucGwiLCJleHBpcmVBdCI6IjIwMzItMTAtMTEgMDA6MDA6MDAiLCJpc0VudGVycHJpc2VQbHVzIjpmYWxzZX19';

    public function test_command_handler_result_is_split_into_one_message_per_element(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );

        self::assertNull($ecotone->getGateway(CommandBus::class)->sendWithRouting('split', [1, 2, 3]));
        self::assertSame([1, 2, 3], $handler->received);
    }

    public function test_generator_result_is_split_into_one_message_per_element(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(): Generator
            {
                yield 'second' => 2;
                yield 'first' => 1;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split');
        self::assertSame([2, 1], $handler->received);
    }

    public function test_iterable_result_is_split_into_one_message_per_element(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(): iterable
            {
                yield 'second' => 2;
                yield 'first' => 1;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split');
        self::assertSame([2, 1], $handler->received);
    }

    public function test_traversable_result_is_split_into_one_message_per_element(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(): Traversable
            {
                return new ArrayIterator(['second' => 2, 'first' => 1]);
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split');
        self::assertSame([2, 1], $handler->received);
    }

    public function test_keyed_array_result_is_split_into_one_message_per_element(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(): array
            {
                return ['second' => 2, 'first' => 1];
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split');
        self::assertSame([2, 1], $handler->received);
    }

    public function test_internal_handler_result_is_split_into_one_message_per_element(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('start', outputChannelName: 'split')]
            public function start(array $values): array
            {
                return $values;
            }

            #[InternalHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );

        self::assertNull($ecotone->getGateway(CommandBus::class)->sendWithRouting('start', [1, 2, 3]));
        self::assertSame([1, 2, 3], $handler->received);
    }

    public function test_event_handler_result_is_split_into_one_message_per_element(): void
    {
        $handler = new class () {
            public array $received = [];

            #[EventHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );

        $ecotone->publishEventWithRouting('split', [1, 2, 3]);
        self::assertSame([1, 2, 3], $handler->received);
    }

    public function test_split_elements_keep_the_metadata_of_the_handled_message(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value, #[Headers] array $metadata): void
            {
                $this->received[] = [$value, $metadata['tenant']];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split', [1, 2], metadata: ['tenant' => 'acme']);
        self::assertSame([[1, 'acme'], [2, 'acme']], $handler->received);
    }

    public function test_empty_result_sends_no_message(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split', []);
        self::assertSame([], $handler->received);
    }

    public function test_each_handler_attribute_of_a_repeated_handler_is_split_to_its_own_output(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('first', outputChannelName: 'first.parts')]
            #[CommandHandler('second', outputChannelName: 'second.parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('first.parts')]
            public function first(int $value): void
            {
                $this->received[] = ['first', $value];
            }

            #[InternalHandler('second.parts')]
            public function second(int $value): void
            {
                $this->received[] = ['second', $value];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('first', [1, 2]);
        $ecotone->sendCommandWithRouting('second', [3, 4]);
        self::assertSame([['first', 1], ['first', 2], ['second', 3], ['second', 4]], $handler->received);
    }

    public function test_standalone_splitter_is_unchanged(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('start', outputChannelName: 'split')]
            public function start(array $values): array
            {
                return $values;
            }

            #[Splitter('split', outputChannelName: 'parts')]
            public function split(array $values): array
            {
                return array_reverse($values);
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('start', [1, 2]);
        self::assertSame([2, 1], $handler->received);
    }

    public function test_aggregate_command_handler_result_is_split_after_the_aggregate_is_saved(): void
    {
        $aggregate = new #[Aggregate] class () {
            #[Identifier]
            public string $id = 'one';
            public int $total = 0;

            #[CommandHandler('create')]
            public static function create(): self
            {
                return new self();
            }

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                $this->total = array_sum($values);
                return $values;
            }

            #[QueryHandler('total')]
            public function total(): int
            {
                return $this->total;
            }
        };
        $handler = new class () {
            public array $received = [];

            #[InternalHandler('parts')]
            public function receive(int $value, #[Reference] QueryBus $queries): void
            {
                $this->received[] = [$value, $queries->sendWithRouting('total', metadata: ['aggregate.id' => 'one'])];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$aggregate::class, $handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('create');
        $ecotone->sendCommandWithRouting('split', [1, 2], metadata: ['aggregate.id' => 'one']);
        self::assertSame([[1, 3], [2, 3]], $handler->received);
    }

    public function test_saga_event_handler_result_is_split_after_the_saga_is_saved(): void
    {
        $aggregate = new #[Saga] class () {
            #[Identifier]
            public string $id = 'one';
            public int $total = 0;

            #[CommandHandler('create')]
            public static function create(): self
            {
                return new self();
            }

            #[EventHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                $this->total = array_sum($values);
                return $values;
            }

            #[QueryHandler('total')]
            public function total(): int
            {
                return $this->total;
            }
        };
        $handler = new class () {
            public array $received = [];

            #[InternalHandler('parts')]
            public function receive(int $value, #[Reference] QueryBus $queries): void
            {
                $this->received[] = [$value, $queries->sendWithRouting('total', metadata: ['aggregate.id' => 'one'])];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$aggregate::class, $handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('create');
        $ecotone->publishEventWithRouting('split', [1, 2], metadata: ['aggregate.id' => 'one']);
        self::assertSame([[1, 3], [2, 3]], $handler->received);
    }

    public function test_asynchronous_handler_is_split_in_its_consumer_run(): void
    {
        $handler = new class () {
            public array $received = [];
            public int $calls = 0;

            #[Asynchronous('jobs')]
            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                $this->calls++;
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split', [1, 2]);
        self::assertSame([], $handler->received);
        self::assertSame(0, $handler->calls);
        $ecotone->run('jobs');
        self::assertSame([1, 2], $handler->received);
        self::assertSame(1, $handler->calls);
    }

    public function test_split_handler_with_gather_channel_runs_the_gathered_handler_once_with_every_part(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): int
            {
                return $value * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts->results;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('split', [1, 2]);
        $ecotone->run('summaries');
        self::assertSame([[1 => 2, 2 => 4]], $handler->gathered);
    }

    public function test_generator_result_of_a_gathering_split_handler_gathers_every_part(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(): Generator
            {
                yield 'second' => 2;
                yield 'first' => 1;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): int
            {
                return $value * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('split');
        $ecotone->run('summaries');
        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 4, 2 => 2], $handler->gathered[0]->results);
        self::assertSame([2, 1], $handler->gathered[0]->originalPayload);
    }

    public function test_traversable_result_of_a_gathering_split_handler_gathers_every_part(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(): Traversable
            {
                return new ArrayIterator(['second' => 2, 'first' => 1]);
            }

            #[InternalHandler('parts')]
            public function receive(int $value): int
            {
                return $value * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('split');
        $ecotone->run('summaries');
        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 4, 2 => 2], $handler->gathered[0]->results);
        self::assertSame([2, 1], $handler->gathered[0]->originalPayload);
    }

    public function test_keyed_array_result_of_a_gathering_split_handler_gathers_every_part(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(): array
            {
                return ['second' => 2, 'first' => 1];
            }

            #[InternalHandler('parts')]
            public function receive(int $value): int
            {
                return $value * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('split');
        $ecotone->run('summaries');
        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 4, 2 => 2], $handler->gathered[0]->results);
        self::assertSame([2, 1], $handler->gathered[0]->originalPayload);
    }

    public function test_split_handler_without_output_channel_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('split')]
            #[Splitter]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split has no outputChannelName on #[CommandHandler(\'split\')]. Set outputChannelName on every handler attribute, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_class_routed_command_handler_without_output_names_its_attribute(): void
    {
        $handler = new class () {
            #[CommandHandler]
            #[Splitter]
            public function split(HandlerSplitCommand $command): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split has no outputChannelName on #[CommandHandler]. Set outputChannelName on every handler attribute, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: []);
    }

    public function test_class_routed_command_handler_without_endpoint_id_names_its_attribute(): void
    {
        $handler = new class () {
            #[CommandHandler(outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(HandlerSplitCommand $command): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split uses gatherChannelName without an explicit endpointId on #[CommandHandler]. Set endpointId on every handler attribute, or remove gatherChannelName from #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: []);
    }

    public function test_class_routed_event_handler_without_output_names_its_attribute(): void
    {
        $handler = new class () {
            #[EventHandler]
            #[Splitter]
            public function split(HandlerSplitCommand $command): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split has no outputChannelName on #[EventHandler]. Set outputChannelName on every handler attribute, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: []);
    }

    public function test_class_routed_event_handler_without_endpoint_id_names_its_attribute(): void
    {
        $handler = new class () {
            #[EventHandler(outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(HandlerSplitCommand $command): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split uses gatherChannelName without an explicit endpointId on #[EventHandler]. Set endpointId on every handler attribute, or remove gatherChannelName from #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: []);
    }

    public function test_repeated_split_handler_names_the_attribute_without_output(): void
    {
        $handler = new class () {
            #[CommandHandler('first', outputChannelName: 'parts')]
            #[CommandHandler('split')]
            #[Splitter]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split has no outputChannelName on #[CommandHandler(\'split\')]. Set outputChannelName on every handler attribute, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_stacked_splitter_input_channel_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter(inputChannelName: 'other')]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split sets inputChannelName on #[Splitter]. Remove inputChannelName from #[Splitter] and configure routingKey on #[CommandHandler], or move #[Splitter] to a separate method.');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_eventhandler_splitter_input_channel_names_its_parameter(): void
    {
        $handler = new class () {
            #[EventHandler('split', outputChannelName: 'parts')]
            #[Splitter(inputChannelName: 'other')]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split sets inputChannelName on #[Splitter]. Remove inputChannelName from #[Splitter] and configure listenTo on #[EventHandler], or move #[Splitter] to a separate method.');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_internalhandler_splitter_input_channel_names_its_parameter(): void
    {
        $handler = new class () {
            #[InternalHandler('split', outputChannelName: 'parts')]
            #[Splitter(inputChannelName: 'other')]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split sets inputChannelName on #[Splitter]. Remove inputChannelName from #[Splitter] and configure inputChannelName on #[InternalHandler], or move #[Splitter] to a separate method.');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_stacked_splitter_endpoint_id_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter(endpointId: 'other')]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split sets endpointId on #[Splitter]. Remove endpointId from #[Splitter] and configure it on the handler, or move #[Splitter] to a separate method.');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_stacked_splitter_output_channel_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter(outputChannelName: 'other')]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split sets outputChannelName on #[Splitter]. Remove outputChannelName from #[Splitter] and configure it on the handler, or move #[Splitter] to a separate method.');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_split_handler_without_iterable_return_type_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(): string
            {
                return 'value';
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split does not declare an iterable return type. Return an array or iterable of output messages, move #[Splitter] to a separate iterable handler, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_query_handler_with_splitter_is_refused(): void
    {
        $handler = new class () {
            #[QueryHandler('split')]
            #[Splitter]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split is a query handler whose caller expects one reply. Use #[CommandHandler], #[EventHandler] or #[InternalHandler] with outputChannelName, move #[Splitter] to a separate method, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_gathering_split_handler_without_explicit_endpoint_id_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split uses gatherChannelName without an explicit endpointId on #[CommandHandler(\'split\')]. Set endpointId on every handler attribute, or remove gatherChannelName from #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_repeated_gathering_split_handler_names_the_attribute_without_endpoint_id(): void
    {
        $handler = new class () {
            #[CommandHandler('first', endpointId: 'first.handler', outputChannelName: 'parts')]
            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split uses gatherChannelName without an explicit endpointId on #[CommandHandler(\'split\')]. Set endpointId on every handler attribute, or remove gatherChannelName from #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_action_sharing_a_route_with_a_factory_with_splitter_is_refused(): void
    {
        $aggregate = new #[Aggregate] class () {
            #[Identifier]
            public string $id = 'one';

            #[CommandHandler('place')]
            public static function create(HandlerSplitCommand $command): self
            {
                return new self();
            }

            #[CommandHandler('place', outputChannelName: 'parts')]
            #[Splitter]
            public function act(HandlerSplitCommand $command): array
            {
                return [1, 2];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $aggregate::class . '::act shares a route with a factory, so its result cannot be split independently. Give the action its own routingKey or listenTo, move the split to a separate handler, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$aggregate::class], enabledModules: []);
    }

    public function test_changing_headers_split_handler_is_refused(): void
    {
        $handler = new class () {
            #[InternalHandler('split', outputChannelName: 'parts', changingHeaders: true)]
            #[Splitter]
            public function split(): array
            {
                return ['tenant' => 'acme'];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split changes headers instead of returning output messages. Remove #[ChangingHeaders] or set changingHeaders: false, move the split to a separate handler, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: [], licenceKey: self::ENTERPRISE_LICENCE);
    }

    public function test_changing_headers_attribute_with_splitter_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            #[ChangingHeaders]
            #[Splitter]
            public function split(): array
            {
                return ['tenant' => 'acme'];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split changes headers instead of returning output messages. Remove #[ChangingHeaders] or set changingHeaders: false, move the split to a separate handler, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: [], licenceKey: self::ENTERPRISE_LICENCE);
    }

    public function test_projection_event_handler_with_splitter_is_refused(): void
    {
        $handler = new #[Projection('orders'), FromStream('orders')] class () {
            #[EventHandler('order.placed', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $event): array
            {
                return [1, 2];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split belongs to a projection whose events are replayed during rebuild and backfill. Move the split to a separate event handler outside the projection, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: [], licenceKey: self::ENTERPRISE_LICENCE);
    }

    public function test_outer_stacked_splitter_refuses_an_ungathered_splitter_at_bootstrap(): void
    {
        $handler = new class () {
            #[CommandHandler('split', endpointId: 'outer', outputChannelName: 'rows')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(array $rows): array
            {
                return $rows;
            }

            #[Splitter('rows', endpointId: 'inner', outputChannelName: 'cells')]
            public function rows(array $cells): array
            {
                return $cells;
            }

            #[InternalHandler('cells')]
            public function cell(int $cell): int
            {
                return $cell;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summary(GatheredParts $parts): void
            {
            }
        };
        $this->expectExceptionMessage("Splitter 'inner' has no gatherChannelName inside a gathered flow, so its sub-parts cannot be accounted for as one outer part. Set gatherChannelName on this #[Splitter], or send the sub-work through CommandBus::send() or EventBus::publish() to leave the gathered flow.");
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: [], licenceKey: self::ENTERPRISE_LICENCE);
    }

    public function test_inner_stacked_splitter_is_refused_at_bootstrap_inside_a_gathered_flow(): void
    {
        $handler = new class () {
            #[Splitter('split', endpointId: 'outer', outputChannelName: 'rows', gatherChannelName: 'summary')]
            public function split(array $rows): array
            {
                return $rows;
            }

            #[InternalHandler('rows', outputChannelName: 'cells')]
            #[Splitter]
            public function rows(array $cells): array
            {
                return $cells;
            }

            #[InternalHandler('cells')]
            public function cell(int $cell): int
            {
                return $cell;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summary(GatheredParts $parts): void
            {
            }
        };
        $this->expectExceptionMessage("Splitter '" . $handler::class . "::rows' has no gatherChannelName inside a gathered flow, so its sub-parts cannot be accounted for as one outer part. Set gatherChannelName on this #[Splitter], or send the sub-work through CommandBus::send() or EventBus::publish() to leave the gathered flow.");
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$handler::class], containerOrAvailableServices: [$handler], enabledModules: [], licenceKey: self::ENTERPRISE_LICENCE);
    }

    public function test_aggregate_factory_with_splitter_is_refused(): void
    {
        $aggregate = new #[Aggregate] class () {
            #[Identifier]
            public string $id = 'one';

            #[CommandHandler('create', outputChannelName: 'parts')]
            #[Splitter]
            public static function create(): self
            {
                return new self();
            }
        };
        $this->expectExceptionMessage('Split handler ' . $aggregate::class . '::create is an aggregate or saga factory whose output contains saved identifiers. Split from an action method, use a separate splitter handler receiving the factory output, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$aggregate::class], enabledModules: []);
    }

    public function test_saga_factory_with_splitter_is_refused(): void
    {
        $aggregate = new #[Saga] class () {
            #[Identifier]
            public string $id = 'one';

            #[CommandHandler('create', outputChannelName: 'parts')]
            #[Splitter]
            public static function create(): self
            {
                return new self();
            }
        };
        $this->expectExceptionMessage('Split handler ' . $aggregate::class . '::create is an aggregate or saga factory whose output contains saved identifiers. Split from an action method, use a separate splitter handler receiving the factory output, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$aggregate::class], enabledModules: []);
    }

    public function test_event_sourced_action_returning_events_to_record_with_splitter_is_refused(): void
    {
        $aggregate = new #[EventSourcingAggregate] class () {
            use WithAggregateVersioning;
            #[Apply]
            public function apply(stdClass $event): void
            {
            }

            #[Identifier]
            public string $id = 'one';

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $aggregate::class . '::split returns events that Ecotone records for an event-sourced aggregate or saga. Record events with #[AggregateEvents] and return an independent iterable result, use a separate splitter handler, or remove #[Splitter].');
        EcotoneLite::bootstrapFlowTesting(classesToResolve: [$aggregate::class], enabledModules: []);
    }

    public function test_replayed_message_of_a_gathering_split_handler_lands_in_the_same_group(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): int
            {
                return $value * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts->results;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('split', [1, 2], metadata: [MessageHeaders::MESSAGE_ID => '019b76da-a000-7000-8000-000000000001']);
        $ecotone->sendCommandWithRouting('split', [1, 2], metadata: [MessageHeaders::MESSAGE_ID => '019b76da-a000-7000-8000-000000000001']);
        $ecotone->run('summaries');
        self::assertSame([[1 => 2, 2 => 4]], $handler->gathered);
        self::assertCount(1, $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('summary.handler'));
    }

    public function test_interceptors_run_once_around_a_split_handler(): void
    {
        $handler = new class () {
            public int $interceptions = 0;
            public int $before = 0;
            public int $after = 0;
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[Around(pointcut: '*')]
            public function intercept(MethodInvocation $invocation): mixed
            {
                if ($invocation->getMethodName() !== 'receive') {
                    $this->interceptions++;
                }
                return $invocation->proceed();
            }

            #[Before(pointcut: CommandHandler::class)]
            public function before(): void
            {
                $this->before++;
            }

            #[After(pointcut: CommandHandler::class)]
            public function after(): void
            {
                $this->after++;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split', [1, 2]);
        self::assertSame([1, 2], $handler->received);
        self::assertSame(2, $handler->interceptions);
        self::assertSame(1, $handler->before);
        self::assertSame(1, $handler->after);
    }

    public function test_flow_graph_shows_a_split_handler_as_one_node_without_its_hidden_channel(): void
    {
        $handler = new class () {
            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts', endpointId: 'receive.handler')]
            public function receive(int $value): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
        )->getMessageFlowGraph();
        self::assertCount(1, $graph->nodesOfKind(FlowNodeKind::COMMAND_HANDLER));
        self::assertSame([], $graph->nodesOfKind(FlowNodeKind::SPLITTER));
        self::assertTrue($graph->node('handler:split.handler')->attributes['splitting']);
        self::assertStringEndsWith(' (splitting)', $graph->node('handler:split.handler')->label);
        self::assertSame(['channel:parts'], array_map(static fn ($node): string => $node->id, $graph->nodesOfKind(FlowNodeKind::CHANNEL)));
    }

    public function test_stacked_splitter_required_interceptor_names_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter(requiredInterceptorNames: ['audit'])]
            public function split(): array
            {
                return [];
            }
        };
        $this->expectExceptionMessage('Split handler ' . $handler::class . '::split sets requiredInterceptorNames on #[Splitter]. Move the names to the handler attribute requiredInterceptorNames so they run once around the handler, or move #[Splitter] to a separate method.');
        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_each_event_handler_attribute_is_split_to_its_own_output(): void
    {
        $handler = new class () {
            public array $received = [];

            #[EventHandler('first', outputChannelName: 'first.parts')]
            #[EventHandler('second', outputChannelName: 'second.parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('first.parts')]
            public function first(int $value): void
            {
                $this->received[] = ['first', $value];
            }

            #[InternalHandler('second.parts')]
            public function second(int $value): void
            {
                $this->received[] = ['second', $value];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->publishEventWithRouting('first', [1, 2]);
        $ecotone->publishEventWithRouting('second', [3, 4]);
        self::assertSame([['first', 1], ['first', 2], ['second', 3], ['second', 4]], $handler->received);
    }

    public function test_each_internal_handler_attribute_is_split_to_its_own_output(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('start.first', outputChannelName: 'first')]
            public function startFirst(array $values): array
            {
                return $values;
            }

            #[CommandHandler('start.second', outputChannelName: 'second')]
            public function startSecond(array $values): array
            {
                return $values;
            }

            #[InternalHandler('first', outputChannelName: 'first.parts')]
            #[InternalHandler('second', outputChannelName: 'second.parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('first.parts')]
            public function first(int $value): void
            {
                $this->received[] = ['first', $value];
            }

            #[InternalHandler('second.parts')]
            public function second(int $value): void
            {
                $this->received[] = ['second', $value];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('start.first', [1, 2]);
        $ecotone->sendCommandWithRouting('start.second', [3, 4]);
        self::assertSame([['first', 1], ['first', 2], ['second', 3], ['second', 4]], $handler->received);
    }

    public function test_asynchronous_event_handler_is_split_in_its_consumer_run(): void
    {
        $handler = new class () {
            public array $received = [];
            public int $calls = 0;

            #[Asynchronous('jobs')]
            #[EventHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                $this->calls++;
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->publishEventWithRouting('split', [1, 2]);
        self::assertSame([], $handler->received);
        self::assertSame(0, $handler->calls);
        $ecotone->run('jobs');
        self::assertSame([1, 2], $handler->received);
        self::assertSame(1, $handler->calls);
    }

    public function test_asynchronous_internal_handler_is_split_in_its_consumer_run(): void
    {
        $handler = new class () {
            public array $received = [];
            public int $calls = 0;

            #[CommandHandler('start', outputChannelName: 'split')]
            public function start(array $values): array
            {
                return $values;
            }

            #[Asynchronous('jobs')]
            #[InternalHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                $this->calls++;
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('start', [1, 2]);
        self::assertSame([], $handler->received);
        self::assertSame(0, $handler->calls);
        $ecotone->run('jobs');
        self::assertSame([1, 2], $handler->received);
        self::assertSame(1, $handler->calls);
    }

    public function test_event_sourced_action_with_recorded_events_splits_its_independent_result_after_save(): void
    {
        $aggregate = new #[EventSourcingAggregate] class () {
            use WithEvents;
            use WithAggregateVersioning;
            #[Identifier]
            public string $id = 'one';
            public int $total = 0;

            #[CommandHandler('create')]
            public static function create(): self
            {
                $aggregate = new self();
                $aggregate->recordThat((object) ['id' => 'one', 'total' => 0]);
                return $aggregate;
            }

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                $this->recordThat((object) ['id' => $this->id, 'total' => array_sum($values)]);
                return $values;
            }

            #[Apply]
            public function apply(stdClass $event): void
            {
                $this->id = $event->id;
                $this->total = $event->total;
            }

            #[QueryHandler('total')]
            public function total(): int
            {
                return $this->total;
            }
        };
        $handler = new class () {
            public array $received = [];

            #[InternalHandler('parts')]
            public function receive(int $value, #[Reference] QueryBus $queries): void
            {
                $this->received[] = [$value, $queries->sendWithRouting('total', metadata: ['aggregate.id' => 'one'])];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$aggregate::class, $handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('create');
        $ecotone->sendCommandWithRouting('split', [1, 2], metadata: ['aggregate.id' => 'one']);
        self::assertSame([[1, 3], [2, 3]], $handler->received);
    }

    public function test_empty_gathering_split_handler_matches_standalone_empty_gathering(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): int
            {
                return $value * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts->results;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('split', []);
        $ecotone->run('summaries');
        self::assertSame([[]], $handler->gathered);
    }

    public function test_required_interceptor_names_run_once_around_a_split_handler(): void
    {
        $handler = new class () {
            public int $interceptions = 0;
            public int $before = 0;
            public int $after = 0;
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts', requiredInterceptorNames: [self::class])]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[Around]
            public function intercept(MethodInvocation $invocation): mixed
            {
                if ($invocation->getMethodName() === 'split') {
                    $this->interceptions++;
                }
                return $invocation->proceed();
            }

            #[Before]
            public function before(): void
            {
                $this->before++;
            }

            #[After]
            public function after(): void
            {
                $this->after++;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('split', [1, 2]);
        self::assertSame([1, 2], $handler->received);
        self::assertSame(1, $handler->interceptions);
        self::assertSame(1, $handler->before);
        self::assertSame(1, $handler->after);
    }

    public function test_class_routed_command_handler_output_is_split(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler(outputChannelName: 'parts')]
            #[Splitter]
            public function split(HandlerSplitCommand $command): array
            {
                return $command->values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, HandlerSplitCommand::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommand(new HandlerSplitCommand([1, 2]));
        self::assertSame([1, 2], $handler->received);
    }

    public function test_gateway_handler_output_is_split(): void
    {
        $handler = new class () {
            public array $received = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            #[Splitter]
            public function split(HandlerSplitCommand $command): array
            {
                return $command->values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): void
            {
                $this->received[] = $value;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, HandlerSplitCommand::class, HandlerSplitGateway::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->getGateway(HandlerSplitGateway::class)->split(new HandlerSplitCommand([1, 2]));
        self::assertSame([1, 2], $handler->received);
    }

    public function test_tracing_shows_the_handler_without_the_hidden_splitter_channel(): void
    {
        $handler = new class () {
            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts', endpointId: 'receive.handler')]
            public function receive(int $value): void
            {
            }
        };
        $recorder = new InMemorySpanRecorder();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, InMemorySpanRecorder::class => $recorder],
            extensionObjects: [TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)],
            enabledModules: [],
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('split', [1, 2]);
        $endpoints = array_values(array_filter(array_map(static fn ($span): ?string => $span->attributes[SpanAttribute::ENDPOINT_ID] ?? null, $recorder->recordedSpans())));
        self::assertSame(['split.handler', 'receive.handler', 'receive.handler'], $endpoints);
    }

    public function test_asynchronous_aggregate_action_splits_after_save(): void
    {
        $aggregate = new #[Aggregate] class () {
            #[Identifier]
            public string $id = 'one';
            public int $total = 0;

            #[CommandHandler('create')]
            public static function create(): self
            {
                return new self();
            }

            #[Asynchronous('split.jobs')]
            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                $this->total = array_sum($values);
                return $values;
            }

            #[QueryHandler('total')]
            public function total(): int
            {
                return $this->total;
            }
        };
        $handler = new class () {
            public array $received = [];

            #[InternalHandler('parts')]
            public function receive(int $value, #[Reference] QueryBus $queries): void
            {
                $this->received[] = [$value, $queries->sendWithRouting('total', metadata: ['aggregate.id' => 'one'])];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$aggregate::class, $handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('create');
        $ecotone->sendCommandWithRouting('split', [1, 2], metadata: ['aggregate.id' => 'one']);
        self::assertSame([], $handler->received);
        $ecotone->run('split.jobs');
        self::assertSame([[1, 3], [2, 3]], $handler->received);
    }

    public function test_asynchronous_saga_action_splits_after_save(): void
    {
        $aggregate = new #[Saga] class () {
            #[Identifier]
            public string $id = 'one';
            public int $total = 0;

            #[CommandHandler('create')]
            public static function create(): self
            {
                return new self();
            }

            #[Asynchronous('split.jobs')]
            #[EventHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter]
            public function split(array $values): array
            {
                $this->total = array_sum($values);
                return $values;
            }

            #[QueryHandler('total')]
            public function total(): int
            {
                return $this->total;
            }
        };
        $handler = new class () {
            public array $received = [];

            #[InternalHandler('parts')]
            public function receive(int $value, #[Reference] QueryBus $queries): void
            {
                $this->received[] = [$value, $queries->sendWithRouting('total', metadata: ['aggregate.id' => 'one'])];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$aggregate::class, $handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
        $ecotone->sendCommandWithRouting('create');
        $ecotone->publishEventWithRouting('split', [1, 2], metadata: ['aggregate.id' => 'one']);
        self::assertSame([], $handler->received);
        $ecotone->run('split.jobs');
        self::assertSame([[1, 3], [2, 3]], $handler->received);
    }

    public function test_asynchronous_split_handler_gathers_every_part(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[Asynchronous('split.jobs')]
            #[CommandHandler('split', endpointId: 'split.handler', outputChannelName: 'parts')]
            #[Splitter(gatherChannelName: 'summary')]
            public function split(array $values): array
            {
                return $values;
            }

            #[InternalHandler('parts')]
            public function receive(int $value): int
            {
                return $value * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts->results;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('split', [1, 2]);
        $ecotone->run('split.jobs');
        $ecotone->run('summaries');
        self::assertSame([[1 => 2, 2 => 4]], $handler->gathered);
    }
}

final class HandlerSplitCommand
{
    public function __construct(public readonly array $values)
    {
    }
}

interface HandlerSplitGateway
{
    #[MessageGateway('split')]
    public function split(HandlerSplitCommand $command): void;
}
