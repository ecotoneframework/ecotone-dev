<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Collecting;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CollectingTest extends TestCase
{
    public function test_collected_handler_without_asynchronous_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' requires an asynchronous channel. Add #[Asynchronous('channelName')] to the handler, or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_collected_handler_without_max_size_or_max_wait_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' needs a release limit. Set maxSize or maxWait on #[Collected], or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
        );
    }

    public function test_collected_handler_requires_an_enterprise_licence(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' requires an Ecotone Enterprise licence. Provide licenceKey in the framework configuration or EcotoneLite, start a trial at https://ecotone.tech/pricing, or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
        );
    }

    public function test_collected_event_handler_without_explicit_subscription_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[EventHandler(endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect.handler' cannot infer its subscription from the batch parameter. Set listenTo on #[EventHandler], or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_command_handler_without_explicit_subscription_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler(endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect.handler' cannot infer its subscription from the batch parameter. Set routingKey on #[CommandHandler], or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_query_handler_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[QueryHandler('collect')]
            public function collect(mixed $batch): array
            {
                return [];
            }
        };

        $this->expectExceptionMessage("Method 'collect' is marked #[Collected] but is not an allowed message handler: a query caller waits for an answer. Stack #[Collected] on a service #[CommandHandler], #[EventHandler] or #[InternalHandler] with #[Asynchronous], or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_handler_on_an_aggregate_is_refused_at_bootstrap(): void
    {
        $handler = new #[Aggregate] class () {
            #[Identifier]
            public string $id = 'batch';

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public static function collect(mixed $batch): self
            {
                return new self();
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' belongs to an aggregate or saga, but a batch has no single instance to load. Move #[Collected] to a service #[CommandHandler], #[EventHandler] or #[InternalHandler], or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_handler_on_a_saga_is_refused_at_bootstrap(): void
    {
        $handler = new #[Saga] class () {
            #[Identifier]
            public string $id = 'batch';

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public static function collect(mixed $batch): self
            {
                return new self();
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' belongs to an aggregate or saga, but a batch has no single instance to load. Move #[Collected] to a service #[CommandHandler], #[EventHandler] or #[InternalHandler], or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_and_gathered_on_one_handler_are_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Gathered]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' also has #[Gathered]: collecting ordinary messages and gathering split parts cannot share a handler. Remove #[Gathered] to collect ordinary messages, or remove #[Collected] to gather split parts.");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_without_an_ordinary_handler_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Method 'collect' is marked #[Collected] but has no ordinary message handler attribute. Stack it on a service #[CommandHandler], #[EventHandler] or #[InternalHandler], or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_handler_runs_once_when_the_batch_reaches_max_size(): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);

        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame(['first', 'second'], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame(2, count($handler->batches[0]->messages));
        self::assertNull($handler->batches[0]->groupKey);
    }

    public function test_collected_handler_requires_the_typed_batch_as_its_first_parameter(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(array $batch): void
            {
            }
        };
        $this->expectExceptionMessage("Collected handler 'collect' must receive Ecotone\\Api\\Messaging\\CollectedMessages as its first payload parameter. Change that parameter type to CollectedMessages, or remove #[Collected].");
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_max_wait_requires_a_delayable_asynchronous_channel(): void
    {
        $handler = new class () {
            #[Collected(maxWait: new TimeSpan(seconds: 10))]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage("Collected handler 'collect' has maxWait but asynchronous channel 'batches' cannot hold delayed messages. Use a channel builder implementing DelayedMessageChannelBuilder with delay support, enable delays on this channel, or remove maxWait from #[Collected].");
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches', delayable: false)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_handler_refuses_zero_max_size_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 0)]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage("Collected handler 'collect' maxSize is 0; maxSize must be at least 1. Set maxSize to a positive integer, omit maxSize and set maxWait, or remove #[Collected].");
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_handler_refuses_zero_max_wait_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxWait: new TimeSpan(milliseconds: 0))]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage("Collected handler 'collect' maxWait is 0 milliseconds; maxWait must be greater than 0. Set a positive TimeSpan, omit maxWait and set maxSize, or remove #[Collected].");
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_internal_handler_without_explicit_subscription_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[InternalHandler('', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage("Collected handler 'collect.handler' cannot infer its subscription from the batch parameter. Set inputChannelName on #[InternalHandler], or remove #[Collected].");
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_handler_requires_durable_storage_outside_flow_testing(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage("Collected handler 'collect.handler' requires durable storage outside flow testing: batches must survive restarts and be shared between consumers. Install ecotone/dbal, enable ModulePackageList::DBAL_PACKAGE, and run ecotone:migration:database:setup --initialize --feature=gathering before consuming messages. Use EcotoneLite::bootstrapFlowTesting() for in-memory tests, or remove #[Collected].");
        EcotoneLite::bootstrap(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }


    public function test_collected_internal_handler_requires_an_explicit_endpoint_id(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[InternalHandler('collect')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage('should have endpointId defined for handling asynchronously');
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_command_handler_requires_an_explicit_endpoint_id(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage('should have endpointId defined for handling asynchronously');
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_event_handler_requires_an_explicit_endpoint_id(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[EventHandler(listenTo: 'collect.event')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage('should have endpointId defined for handling asynchronously');
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
    public function test_collected_handler_refuses_negative_max_size_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: -1)]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage("Collected handler 'collect' maxSize is -1; maxSize must be at least 1. Set maxSize to a positive integer, omit maxSize and set maxWait, or remove #[Collected].");
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_collected_handler_refuses_negative_max_wait_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxWait: new TimeSpan(milliseconds: -1))]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $this->expectExceptionMessage("Collected handler 'collect' maxWait is -1 milliseconds; maxWait must be greater than 0. Set a positive TimeSpan, omit maxWait and set maxSize, or remove #[Collected].");
        EcotoneLite::bootstrapFlowTesting(
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_endpoint_ids_with_core_suffixes_compile_independent_collected_handlers(): void
    {
        $handler = new class () {
            public array $first = [];
            public array $second = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect.collision.first', endpointId: 'collect.collision')]
            public function first(CollectedMessages $batch): void
            {
                $this->first[] = array_column($batch->messages, 'payload');
            }

            #[Collected(maxSize: 1)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect.collision.second', endpointId: 'collect.collision.core')]
            public function second(CollectedMessages $batch): void
            {
                $this->second[] = array_column($batch->messages, 'payload');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            licenceKey: LicenceTesting::VALID_LICENCE,
            enabledModules: [],
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('collect.collision.first', 'one');
        $ecotone->sendCommandWithRouting('collect.collision.first', 'two');
        $ecotone->sendCommandWithRouting('collect.collision.second', 'third');
        $ecotone->run('batches');
        self::assertSame([['one', 'two']], $handler->first);
        self::assertSame([['third']], $handler->second);
    }

}
