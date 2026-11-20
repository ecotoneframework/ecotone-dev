<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Splitter;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Gateway\CommandBus;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 */
final class HandlerSplitterTest extends TestCase
{
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

}
