<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\InvalidArgumentException;
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
final class PulseMultiTenantTest extends DbalMessagingTestCase
{
    private const TRACE_OF_TENANT_A = '0af7651916cd43dd8448eb211c80319c';
    private const TRACE_OF_TENANT_B = '1bf7651916cd43dd8448eb211c80319d';
    private const CALLER_SPAN = 'b7ad6b7169203331';

    private object $pulse;
    private object $orders;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public function test_a_snapshot_request_without_a_tenant_advertises_the_tenants_and_leaves_out_the_tenant_scoped_sections(): void
    {
        $this->bootstrap();

        $snapshot = $this->requestSnapshot();

        self::assertSame(['header' => 'tenant', 'tenants' => ['tenant_a', 'tenant_b']], $snapshot['tenancy']);
        self::assertArrayHasKey('overview', $snapshot);
        self::assertArrayHasKey('flowGraph', $snapshot);
        self::assertSame(['enabled' => true], $snapshot['deadLetter']);
        self::assertSame('dbal', $snapshot['tracing']['storage']);
        self::assertArrayNotHasKey('deadLetters', $snapshot);
        self::assertArrayNotHasKey('flowTypes', $snapshot['tracing']);
        self::assertArrayNotHasKey('tenant', $snapshot);
    }

    public function test_a_snapshot_request_with_a_tenant_carries_the_dead_letters_of_that_tenant_only(): void
    {
        $this->bootstrap();
        $this->failOrder('milk', 'tenant_a');
        $this->failOrder('bread', 'tenant_b');
        $this->failOrder('eggs', 'tenant_b');

        $ofTenantA = $this->requestSnapshot('tenant_a');
        $ofTenantB = $this->requestSnapshot('tenant_b');

        self::assertSame('tenant_a', $ofTenantA['tenant']);
        self::assertSame(1, $ofTenantA['deadLetters']['total']);
        self::assertSame(['milk'], array_column($ofTenantA['deadLetters']['recent'], 'payload'));
        self::assertSame('tenant_b', $ofTenantB['tenant']);
        self::assertSame(2, $ofTenantB['deadLetters']['total']);
        self::assertEqualsCanonicalizing(['bread', 'eggs'], array_column($ofTenantB['deadLetters']['recent'], 'payload'));
        self::assertArrayNotHasKey('tenancy', $ofTenantA);
        self::assertArrayNotHasKey('overview', $ofTenantA);
    }

    public function test_a_snapshot_request_with_a_tenant_carries_the_flow_types_of_that_tenant_only(): void
    {
        $this->bootstrap();
        $this->orderService->sendCommandWithRouting('ticket.create', 'first', metadata: ['tenant' => 'tenant_a', MessageHeaders::TRACEPARENT => (new SpanContext(self::TRACE_OF_TENANT_A, self::CALLER_SPAN))->toTraceparent()]);

        $ofTenantA = $this->requestSnapshot('tenant_a');
        $ofTenantB = $this->requestSnapshot('tenant_b');

        self::assertSame(['ticket.create'], array_column($ofTenantA['tracing']['flowTypes'], 'flowType'));
        self::assertSame([], $ofTenantB['tracing']['flowTypes']);
        self::assertArrayNotHasKey('storage', $ofTenantA['tracing']);
    }

    public function test_replaying_dead_letters_by_ids_replays_them_in_the_tenant_that_was_asked(): void
    {
        $this->bootstrap();
        $this->failOrder('milk', 'tenant_a');
        $this->failOrder('bread', 'tenant_b');
        $messageId = $this->requestSnapshot('tenant_a')['deadLetters']['recent'][0]['messageId'];

        $this->orders->failing = false;
        $snapshot = $this->ask('ecotone.pulse.dead_letter.replay', json_encode(['messageIds' => [$messageId], 'targetChannel' => 'async']), 'tenant_a');
        $this->consumeAsync(1);

        self::assertSame([['milk', 'tenant_a']], $this->orders->replayed);
        self::assertSame('tenant_a', $snapshot['tenant']);
        self::assertSame(0, $snapshot['deadLetters']['total']);
        self::assertSame(1, $this->requestSnapshot('tenant_b')['deadLetters']['total']);
    }

    public function test_replaying_dead_letters_by_filter_replays_them_in_the_tenant_that_was_asked(): void
    {
        $this->bootstrap();
        $this->failOrder('milk', 'tenant_a');
        $this->failOrder('bread', 'tenant_b');
        $this->failOrder('eggs', 'tenant_b');

        $this->orders->failing = false;
        $snapshot = $this->ask('ecotone.pulse.dead_letter.replay', json_encode(['filter' => ['exceptionClass' => RuntimeException::class], 'targetChannel' => 'async']), 'tenant_b');
        $this->consumeAsync(2);

        self::assertEqualsCanonicalizing([['bread', 'tenant_b'], ['eggs', 'tenant_b']], $this->orders->replayed);
        self::assertSame(0, $snapshot['deadLetters']['total']);
        self::assertSame(1, $this->requestSnapshot('tenant_a')['deadLetters']['total']);
    }

    public function test_deleting_dead_letters_deletes_them_from_the_tenant_that_was_asked(): void
    {
        $this->bootstrap();
        $this->failOrder('milk', 'tenant_a');
        $this->failOrder('bread', 'tenant_b');
        $messageId = $this->requestSnapshot('tenant_b')['deadLetters']['recent'][0]['messageId'];

        $byIds = $this->ask('ecotone.pulse.dead_letter.delete', json_encode(['messageIds' => [$messageId]]), 'tenant_b');
        $byFilter = $this->ask('ecotone.pulse.dead_letter.delete', json_encode(['filter' => ['messageName' => 'order.place']]), 'tenant_a');

        self::assertSame(0, $byIds['deadLetters']['total']);
        self::assertSame(0, $byFilter['deadLetters']['total']);
        self::assertSame([], $this->orders->replayed);
    }

    public function test_replaying_and_deleting_by_failure_id_acts_on_the_requested_tenant_only_when_two_tenants_hold_the_same_failure(): void
    {
        $this->bootstrap();
        $this->failOrder('milk', 'tenant_a');
        $this->failOrder('milk', 'tenant_b');
        $failureId = $this->requestSnapshot('tenant_a')['deadLetters']['groups'][0]['failureId'];
        self::assertSame($failureId, $this->requestSnapshot('tenant_b')['deadLetters']['groups'][0]['failureId']);

        $this->orders->failing = false;
        $afterReplay = $this->ask('ecotone.pulse.dead_letter.replay', json_encode(['filter' => ['failureId' => $failureId], 'targetChannel' => 'async']), 'tenant_a');
        $this->consumeAsync(1);
        $afterDelete = $this->ask('ecotone.pulse.dead_letter.delete', json_encode(['filter' => ['failureId' => $failureId]]), 'tenant_b');

        self::assertSame([['milk', 'tenant_a']], $this->orders->replayed);
        self::assertSame(1, $afterReplay['deadLetterAction']['affected']);
        self::assertSame(0, $afterReplay['deadLetters']['total']);
        self::assertSame(1, $afterDelete['deadLetterAction']['affected']);
        self::assertSame(0, $afterDelete['deadLetters']['total']);
    }

    public function test_dbal_channels_on_the_tenant_connection_are_measured_per_tenant_and_left_unmeasured_without_a_tenant(): void
    {
        $this->bootstrap();
        $this->orderService->sendCommandWithRouting('order.queue', 'milk', metadata: ['tenant' => 'tenant_a']);
        $this->orderService->sendCommandWithRouting('order.queue', 'bread', metadata: ['tenant' => 'tenant_a']);

        $withoutTenant = $this->channelsByName($this->requestSnapshot());
        $ofTenantA = $this->channelsByName($this->requestSnapshot('tenant_a'));
        $ofTenantB = $this->channelsByName($this->requestSnapshot('tenant_b'));

        self::assertNull($withoutTenant['tenant_queue']['ready']);
        self::assertNull($withoutTenant['tenant_queue']['probeError']);
        self::assertArrayHasKey('async', $withoutTenant);
        self::assertSame(2, $ofTenantA['tenant_queue']['ready']);
        self::assertSame(0, $ofTenantB['tenant_queue']['ready']);
        self::assertSame(['tenant_queue'], array_keys($ofTenantA));
    }

    public function test_listing_traces_lists_the_traces_of_the_tenant_that_was_asked(): void
    {
        $this->bootstrap();
        $this->orderService->sendCommandWithRouting('ticket.create', 'first', metadata: ['tenant' => 'tenant_a', MessageHeaders::TRACEPARENT => (new SpanContext(self::TRACE_OF_TENANT_A, self::CALLER_SPAN))->toTraceparent()]);
        $this->orderService->sendCommandWithRouting('ticket.create', 'second', metadata: ['tenant' => 'tenant_b', MessageHeaders::TRACEPARENT => (new SpanContext(self::TRACE_OF_TENANT_B, self::CALLER_SPAN))->toTraceparent()]);

        $ofTenantA = $this->ask('ecotone.pulse.traces.list', json_encode(['flowType' => 'ticket.create']), 'tenant_a');
        $ofTenantB = $this->ask('ecotone.pulse.traces.list', json_encode(['flowType' => 'ticket.create']), 'tenant_b');

        self::assertSame([self::TRACE_OF_TENANT_A], array_column($ofTenantA['traces'], 'traceId'));
        self::assertSame('tenant_a', $ofTenantA['tenant']);
        self::assertSame([self::TRACE_OF_TENANT_B], array_column($ofTenantB['traces'], 'traceId'));
    }

    public function test_asking_for_a_trace_finds_it_only_in_the_tenant_it_ran_for(): void
    {
        $this->bootstrap();
        $this->orderService->sendCommandWithRouting('ticket.create', 'first', metadata: ['tenant' => 'tenant_a', MessageHeaders::TRACEPARENT => (new SpanContext(self::TRACE_OF_TENANT_A, self::CALLER_SPAN))->toTraceparent()]);

        $inTenantA = $this->ask('ecotone.pulse.traces.trace', json_encode(['traceId' => self::TRACE_OF_TENANT_A]), 'tenant_a');
        $inTenantB = $this->ask('ecotone.pulse.traces.trace', json_encode(['traceId' => self::TRACE_OF_TENANT_A]), 'tenant_b');

        self::assertGreaterThan(0, $inTenantA['totalSpans']);
        self::assertSame('tenant_a', $inTenantA['tenant']);
        self::assertSame(0, $inTenantB['totalSpans']);
        self::assertSame('tenant_b', $inTenantB['tenant']);
    }

    public function test_a_tenant_the_service_does_not_advertise_is_refused_instead_of_answered_from_the_default_connection(): void
    {
        $this->bootstrap(withDefaultConnection: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service order_service was asked by Pulse for tenant `tenant_c`, but it advertises only tenant_a, tenant_b, so the answer would come from the default connection and be shown as that tenant. Ask for one of the advertised tenants, or add `tenant_c` to the tenant mapping of MultiTenantConfiguration.');
        $this->requestSnapshot('tenant_c');
    }

    public function test_a_dbal_pulse_channel_on_the_multi_tenant_connection_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Pulse request channel 'order_service' is a DBAL channel on connection reference `" . DbalConnectionReference::DEFAULT . "`, which is the multi-tenant connection, so a Pulse request sent without a tenant could not be read from it. Keep the Pulse channels on a dedicated non-tenant connection: DbalBackedMessageChannelBuilder::create('order_service', 'pulse_connection') with a DbalConnectionFactory registered under that reference, or use a broker transport (AMQP, Redis, SQS or Kafka) for it.");

        $this->bootstrap(pulseChannelsConnection: DbalConnectionReference::DEFAULT);
    }

    public function test_a_dbal_pulse_channel_on_a_dedicated_non_tenant_connection_works_next_to_a_multi_tenant_default(): void
    {
        $this->bootstrap(pulseChannelsConnection: 'pulse_connection');

        self::assertSame('tenant', $this->requestSnapshot()['tenancy']['header']);
    }

    public function test_multi_tenant_configurations_naming_different_tenant_headers_are_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Pulse asks a multi-tenant service for one tenant at a time with a single tenant header, but the MultiTenantConfigurations of this service use the headers `tenant`, `customer`, so it could not tell which header names the tenant. Give every MultiTenantConfiguration the same tenant header name, or remove the PulseConfiguration.');

        $this->bootstrap(additionalMultiTenantConfiguration: MultiTenantConfiguration::create('customer', ['customer_a' => 'tenant_a_connection'], 'reporting_connection'));
    }

    public static function interceptorPaths(): iterable
    {
        yield 'transaction' => [DbalConfiguration::createWithDefaults()->withDeadLetter(true)->withDeduplication(false)->withClearAndFlushObjectManagerOnAsynchronousEndpoints(false)->withClearAndFlushObjectManagerOnCommandBus(false)];
        yield 'deduplication' => [DbalConfiguration::createWithDefaults()->withDeadLetter(true)->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false)->withClearAndFlushObjectManagerOnAsynchronousEndpoints(false)->withClearAndFlushObjectManagerOnCommandBus(false)];
        yield 'object manager' => [DbalConfiguration::createWithDefaults()->withDeadLetter(true)->withDeduplication(false)->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false)->withClearAndFlushObjectManagerOnAsynchronousEndpoints(true)->withClearAndFlushObjectManagerOnCommandBus(false)];
    }

    #[DataProvider('interceptorPaths')]
    public function test_a_snapshot_without_a_tenant_is_answered_on_every_interceptor_path_while_an_application_message_without_a_tenant_still_fails(DbalConfiguration $dbalConfiguration): void
    {
        $this->bootstrap(dbalConfiguration: $dbalConfiguration, flowTracking: false);

        $answered = $this->requestSnapshot();

        self::assertSame('tenant', $answered['tenancy']['header']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lack of context about tenant in Message Headers. Please add `tenant` header metadata to your message.');
        $this->orderService->sendCommandWithRouting('order.untenanted', 'milk');
        $this->orderService->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: true));
    }

    private function failOrder(string $order, string $tenant): void
    {
        $this->orderService->sendCommandWithRouting('order.place', $order, metadata: ['tenant' => $tenant]);
        $this->orderService->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function channelsByName(array $snapshot): array
    {
        return array_column($snapshot['channels'], null, 'name');
    }

    private function consumeAsync(int $messages): void
    {
        $this->orderService->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: $messages, stopOnError: false));
    }

    private function requestSnapshot(?string $tenant = null): array
    {
        return $this->ask('ecotone.pulse.snapshot', '{}', $tenant);
    }

    private function ask(string $routingKey, string $payload, ?string $tenant = null): array
    {
        $queryId = 'query-' . (count($this->pulse->replies) + 1);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, $payload, MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => $queryId, ...($tenant === null ? [] : ['tenant' => $tenant])], replyRoutingKey: 'ecotone.pulse.reply_received');
        $this->orderService->run('order_service', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('ecotone_pulse', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertArrayHasKey($queryId, $this->pulse->replies);

        return json_decode($this->pulse->replies[$queryId], true, flags: JSON_THROW_ON_ERROR);
    }

    private function bootstrap(?string $pulseChannelsConnection = null, bool $withDefaultConnection = false, ?MultiTenantConfiguration $additionalMultiTenantConfiguration = null, ?DbalConfiguration $dbalConfiguration = null, bool $flowTracking = true): void
    {
        $this->orders = new class () {
            public array $replayed = [];
            public bool $failing = true;

            #[Asynchronous('async')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $order, #[Header('tenant')] string $tenant): void
            {
                if ($this->failing) {
                    throw new RuntimeException("Order {$order} could not be placed");
                }

                $this->replayed[] = [$order, $tenant];
            }

            #[Asynchronous('tenant_queue')]
            #[CommandHandler('order.queue', endpointId: 'order.queueing')]
            public function queue(string $order): void
            {
            }

            #[Asynchronous('async')]
            #[CommandHandler('order.untenanted', endpointId: 'order.untenanting')]
            public function untenanted(string $order): void
            {
            }

            #[CommandHandler('ticket.create', endpointId: 'ticket.creating')]
            public function create(string $subject): void
            {
            }
        };
        $this->pulse = new class () {
            public array $replies = [];

            #[InternalHandler('ecotone.pulse.reply_received')]
            public function received(string $reply, #[Header('ecotone_pulse_query_id')] string $queryId): void
            {
                $this->replies[$queryId] = $reply;
            }
        };
        $pulseChannels = $pulseChannelsConnection === null
            ? [SimpleMessageChannelBuilder::createQueueChannel('order_service'), SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse')]
            : [DbalBackedMessageChannelBuilder::create('order_service', $pulseChannelsConnection), DbalBackedMessageChannelBuilder::create('ecotone_pulse', $pulseChannelsConnection)];
        $connections = ['tenant_a_connection' => $this->connectionForTenantA(), 'tenant_b_connection' => $this->connectionForTenantB(), 'pulse_connection' => $this->connectionForTenantA()];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [$this->orders, ...$connections],
            extensionObjects: [
                ...$pulseChannels,
                SimpleMessageChannelBuilder::createQueueChannel('async'),
                DbalBackedMessageChannelBuilder::create('tenant_queue'),
                $withDefaultConnection
                    ? MultiTenantConfiguration::createWithDefaultConnection('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'], 'tenant_a_connection')
                    : MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection']),
                ...($additionalMultiTenantConfiguration === null ? [] : [$additionalMultiTenantConfiguration]),
                $dbalConfiguration ?? DbalConfiguration::createWithDefaults()->withDeadLetter(true),
                ...($flowTracking ? [FlowTrackingConfiguration::createWithDefaults()] : []),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service')->withDefaultErrorChannel('dbal_dead_letter'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC')))->withDatabaseTablesSetUpOnBootstrap(false),
        );
        $this->orderService->runConsoleCommand('ecotone:migration:database:delete', ['force' => true]);
        $this->orderService->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
        $this->pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse, ...$connections],
            extensionObjects: [
                ...$pulseChannels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_service'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
