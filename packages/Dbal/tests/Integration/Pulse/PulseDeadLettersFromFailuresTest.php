<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\BusinessMethod;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseDeadLettersFromFailuresTest extends DbalMessagingTestCase
{
    private object $orders;
    private object $pulse;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public static function failures(): iterable
    {
        yield 'routing key command' => ['order.place', fn (FlowTestSupport $service, FlowTestSupport $pulse) => $service->sendCommandWithRouting('order.place', ['orderId' => 1])];
        yield 'class command' => [PulsePlaceOrder::class, fn (FlowTestSupport $service, FlowTestSupport $pulse) => $service->sendCommand(new PulsePlaceOrder(2))];
        yield 'class event' => [PulseOrderWasPlaced::class, fn (FlowTestSupport $service, FlowTestSupport $pulse) => $service->publishEvent(new PulseOrderWasPlaced(3))];
        yield 'named event' => ['order.shipped', fn (FlowTestSupport $service, FlowTestSupport $pulse) => $service->publishEventWithRouting('order.shipped', ['orderId' => 4])];
        yield 'business interface' => ['order.invoiced', fn (FlowTestSupport $service, FlowTestSupport $pulse) => $service->getGateway(PulseInvoiceGateway::class)->invoice(['orderId' => 5])];
        yield 'distributed command' => ['order.synchronized', fn (FlowTestSupport $service, FlowTestSupport $pulse) => $pulse->getDistributedBus()->sendMessage('order_service', 'order.synchronized', 'sync', MediaType::TEXT_PLAIN)];
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->orders = new class () {
            public bool $failing = true;
            public array $succeeded = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function placeByRoutingKey(array $order): void
            {
                $this->handle('order.place');
            }

            #[Asynchronous('orders')]
            #[CommandHandler(endpointId: 'order.place.class.endpoint')]
            public function placeByClass(PulsePlaceOrder $order): void
            {
                $this->handle(PulsePlaceOrder::class);
            }

            #[Asynchronous('orders')]
            #[EventHandler(endpointId: 'order.placed.class.endpoint')]
            public function whenPlacedByClass(PulseOrderWasPlaced $event): void
            {
                $this->handle(PulseOrderWasPlaced::class);
            }

            #[Asynchronous('orders')]
            #[EventHandler('order.shipped', endpointId: 'order.shipped.endpoint')]
            public function whenShipped(array $event): void
            {
                $this->handle('order.shipped');
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.invoiced', endpointId: 'order.invoiced.endpoint')]
            public function invoice(array $order): void
            {
                $this->handle('order.invoiced');
            }

            #[Distributed]
            #[CommandHandler('order.synchronized', endpointId: 'order.synchronized.endpoint')]
            public function synchronize(string $payload): void
            {
                $this->handle('order.synchronized');
            }

            private function handle(string $handler): void
            {
                if ($this->failing) {
                    throw new RuntimeException("{$handler} failed");
                }

                $this->succeeded[] = $handler;
            }
        };
        $this->pulse = new class () {
            public array $snapshots = [];

            #[\Ecotone\Api\Attribute\InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $connectionFactory = $this->getConnectionFactory();
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class, PulseInvoiceGateway::class],
            containerOrAvailableServices: [$this->orders, DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [
                ...$channels,
                DbalBackedMessageChannelBuilder::create('orders'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service')->withDefaultErrorChannel('dbal_dead_letter'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->orderService->setupDatabaseTables();
        $this->orderService->getGateway(DeadLetterGateway::class)->deleteAll();
        $this->pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function tearDown(): void
    {
        $this->orderService->getGateway(DeadLetterGateway::class)->deleteAll();
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_a_message_that_failed_in_its_handler_is_named_as_the_user_sent_it(string $expectedName, callable $send): void
    {
        $send($this->orderService, $this->pulseService);
        $this->consumeFailures();

        $deadLetters = $this->snapshot()['deadLetters'];

        self::assertSame(1, $deadLetters['total']);
        self::assertSame($expectedName, $deadLetters['recent'][0]['messageName']);
        self::assertSame(RuntimeException::class, $deadLetters['recent'][0]['exceptionClass']);
        self::assertSame([['messageName' => $expectedName, 'exceptionClass' => RuntimeException::class, 'count' => 1]], $deadLetters['groups']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_replaying_by_the_name_in_the_snapshot_runs_only_that_handler_again(string $expectedName, callable $send): void
    {
        foreach (self::failures() as [$name, $sendOther]) {
            $sendOther($this->orderService, $this->pulseService);
        }
        $this->consumeFailures();
        self::assertSame(6, $this->snapshot()['deadLetters']['total']);
        $this->orders->failing = false;

        $deadLetters = $this->ask('ecotone.pulse.dead_letter.replay', json_encode(['filter' => ['messageName' => $expectedName]]))['deadLetters'];
        $this->consumeFailures();

        self::assertSame(5, $deadLetters['total']);
        self::assertSame([$expectedName], $this->orders->succeeded);
    }

    private function consumeFailures(): void
    {
        $this->orderService->run('orders', ExecutionPollingMetadata::createWithTestingSetup(10, 100, false));
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup(10, 100, false));
    }

    private function snapshot(): array
    {
        return $this->ask('ecotone.pulse.snapshot', '{}');
    }

    private function ask(string $routingKey, string $payload): array
    {
        $received = count($this->pulse->snapshots);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, $payload, MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount($received + 1, $this->pulse->snapshots);

        return json_decode($this->pulse->snapshots[$received], true, flags: JSON_THROW_ON_ERROR);
    }
}

interface PulseInvoiceGateway
{
    #[BusinessMethod('order.invoiced')]
    public function invoice(array $order): void;
}

final class PulsePlaceOrder
{
    public function __construct(public readonly int $orderId)
    {
    }
}

final class PulseOrderWasPlaced
{
    public function __construct(public readonly int $orderId)
    {
    }
}
