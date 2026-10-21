<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
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
final class PulseTracesTest extends DbalMessagingTestCase
{
    private object $pulse;
    private object $orders;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_the_snapshot_reports_every_flow_type_with_its_runs_failures_and_entry_durations(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->orderService->sendCommandWithRouting('create.ticket', '100');
        $this->orderService->advanceTimeBy(Duration::seconds(10));
        $this->orderService->sendCommandWithRouting('create.ticket', '200');
        $this->orderService->advanceTimeBy(Duration::seconds(10));
        $this->orderService->sendCommandWithRouting('create.ticket', '300');
        $this->orderService->advanceTimeBy(Duration::seconds(10));
        $this->orderService->sendCommandWithRouting('refund.ticket', 'ticket-1');
        $this->orderService->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $tracing = $this->requestSnapshot()['tracing'];

        self::assertSame($implementation === 'dbal' ? 'dbal' : 'in_memory', $tracing['storage']);
        self::assertSame(604800, $tracing['retention']);
        self::assertSame(
            [
                ['flowType' => 'create.ticket', 'runs' => 3, 'failedRuns' => 0, 'p50Ms' => 200, 'p95Ms' => 300, 'maxMs' => 300, 'lastStartedAt' => '2026-10-20T12:00:20.300000+00:00'],
                ['flowType' => 'refund.ticket', 'runs' => 1, 'failedRuns' => 1, 'p50Ms' => 0, 'p95Ms' => 0, 'maxMs' => 0, 'lastStartedAt' => '2026-10-20T12:00:30.600000+00:00'],
            ],
            $tracing['flowTypes'],
        );
    }

    #[DataProvider('implementations')]
    public function test_the_snapshot_has_no_tracing_section_without_flow_tracking(string $implementation): void
    {
        $this->bootstrap($implementation, flowTracking: false);

        self::assertArrayNotHasKey('tracing', $this->requestSnapshot());
    }

    private function bootstrap(string $implementation, bool $flowTracking = true): void
    {
        $this->orders = new class () {
            #[CommandHandler('create.ticket', endpointId: 'ticket.create')]
            public function create(string $milliseconds, EventBus $eventBus, #[Reference] EcotoneClockInterface $clock): void
            {
                $clock->sleep(Duration::milliseconds((int) $milliseconds));
                $eventBus->publishWithRouting('ticket.created', $milliseconds);
            }

            #[Asynchronous('async_channel')]
            #[EventHandler('ticket.created', endpointId: 'ticket.notify')]
            public function notify(string $milliseconds): void
            {
            }

            #[CommandHandler('refund.ticket', endpointId: 'ticket.refund')]
            public function refund(string $ticketId, CommandBus $commandBus): void
            {
                try {
                    $commandBus->sendWithRouting('payment.reverse', $ticketId);
                } catch (RuntimeException) {
                }
            }

            #[CommandHandler('payment.reverse', endpointId: 'payment.reversal')]
            public function reverse(string $ticketId): void
            {
                throw new RuntimeException("Payment of {$ticketId} could not be reversed");
            }
        };
        $this->pulse = new class () {
            public array $snapshots = [];
            public array $queryResults = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }

            #[InternalHandler('ecotone.pulse.query_result_received')]
            public function queryResult(string $result, #[Header('ecotone_pulse_query_id')] string $queryId): void
            {
                $this->queryResults[$queryId] = $result;
            }
        };
        $channels = [
            SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('order_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('pulse_channel'),
        ];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [$this->orders, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                ...($flowTracking ? [FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($implementation === 'in-memory')] : []),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'))),
        );
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

    private function requestSnapshot(): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode(array_pop($this->pulse->snapshots), true, flags: JSON_THROW_ON_ERROR);
    }
}
