<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Pulse;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DelayedRetry;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Orchestrator;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
final class PulseFeaturesTest extends TestCase
{
    public function test_without_database_transactions_the_instant_retry_defaults_leave_asynchronous_endpoints_and_the_command_bus_alone(): void
    {
        $features = $this->coreFeatures($this->orders());

        $asynchronous = $features['Instant retries on asynchronous endpoints'];
        self::assertFalse($asynchronous['enabled']);
        self::assertSame([], $asynchronous['settings']);
        self::assertStringContainsString('Not configured, and asynchronous endpoints run without a database transaction, so the default retries only asynchronous projections', $asynchronous['hint']);
        self::assertSame('InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(isEnabled: true, retryTimes: 3, retryExceptions: [])', $asynchronous['configuration']);
        $commandBus = $features['Instant retries on the command bus'];
        self::assertFalse($commandBus['enabled']);
        self::assertStringContainsString('Not configured, and the command bus runs without a database transaction', $commandBus['hint']);
        self::assertSame('InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(isEnabled: true, retryTimes: 3, retryExceptions: [])', $commandBus['configuration']);
    }

    public function test_instant_retries_turned_on_retry_an_asynchronous_endpoint_three_more_times_on_any_exception(): void
    {
        $features = $this->coreFeatures($this->orders(), [InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(true)]);

        $asynchronous = $features['Instant retries on asynchronous endpoints'];
        self::assertTrue($asynchronous['enabled']);
        self::assertSame(['Extra attempts' => '3', 'Retried on' => 'any exception'], $asynchronous['settings']);
        self::assertStringContainsString('handled once and, when that fails, tried again right away up to 3 more times (4 attempts in total) on any exception', $asynchronous['hint']);
    }

    public function test_configured_instant_retries_report_their_count_and_exceptions_and_the_endpoints_overriding_them(): void
    {
        $handlers = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
            }
        };

        $features = $this->coreFeatures($handlers, [
            SimpleMessageChannelBuilder::createQueueChannel('orders'),
            InstantRetryConfiguration::createWithDefaults()
                ->withCommandBusRetry(true, 2, [RuntimeException::class])
                ->withAsynchronousEndpointsRetry(false),
        ], [PulseInstantRetryCommandBus::class]);

        $commandBus = $features['Instant retries on the command bus'];
        self::assertTrue($commandBus['enabled']);
        self::assertSame(['Extra attempts' => '2', 'Retried on' => 'RuntimeException'], $commandBus['settings']);
        self::assertSame(['PulseInstantRetryCommandBus: 5 extra attempts on InvalidArgumentException'], $commandBus['handlers']);
        self::assertStringContainsString('up to 2 more times (3 attempts in total) on RuntimeException', $commandBus['hint']);
        self::assertFalse($features['Instant retries on asynchronous endpoints']['enabled']);
        self::assertStringContainsString('with no immediate second attempt', $features['Instant retries on asynchronous endpoints']['hint']);
    }

    public function test_without_a_default_error_channel_error_handling_is_reported_as_disabled_with_the_call_that_enables_it(): void
    {
        $features = $this->coreFeatures($this->orders());

        $errorChannel = $features['Error channel and delayed retries'];
        self::assertFalse($errorChannel['enabled']);
        self::assertSame('not set', $errorChannel['settings']['Default error channel']);
        self::assertSame('3 retries: after 1000 ms, 10000 ms and 100000 ms, then dead letter channel dbal_dead_letter', $errorChannel['settings']['Retries on errorChannel']);
        self::assertStringContainsString('is not sent to an error channel', $errorChannel['hint']);
        self::assertSame("ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel')", $errorChannel['configuration']);
    }

    public function test_a_default_error_channel_and_delayed_retry_handlers_are_reported_with_their_retry_schedule(): void
    {
        $handlers = new class () {
            #[Asynchronous('orders', asynchronousExecution: [new DelayedRetry(initialDelayInMilliseconds: 500, multiplier: 2, maxRetries: 2)])]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
            }
        };

        $features = $this->coreFeatures($handlers, [
            SimpleMessageChannelBuilder::createQueueChannel('orders'),
            ErrorHandlerConfiguration::create('order_errors', RetryTemplateBuilder::fixedBackOff(200)->maxRetries(2)),
        ], serviceConfiguration: ServiceConfiguration::createWithDefaults()->withServiceName('order_service')->withDefaultErrorChannel('order_errors'));

        $errorChannel = $features['Error channel and delayed retries'];
        self::assertTrue($errorChannel['enabled']);
        self::assertSame('order_errors', $errorChannel['settings']['Default error channel']);
        self::assertSame('2 retries: after 200 ms and 200 ms, then no further retry', $errorChannel['settings']['Retries on order_errors']);
        self::assertSame(['order.place.endpoint: 2 retries: after 500 ms and 1000 ms, then no further retry'], $errorChannel['handlers']);
        self::assertStringContainsString("is sent to error channel 'order_errors'", $errorChannel['hint']);
    }

    public function test_distributed_handlers_and_orchestrators_are_listed_under_their_feature(): void
    {
        $handlers = new class () {
            #[Distributed]
            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
            }

            #[Distributed]
            #[EventHandler('billing.invoiced')]
            public function whenInvoiced(string $invoiceId): void
            {
            }

            #[Orchestrator(inputChannelName: 'order.fulfil')]
            public function fulfil(): array
            {
                return ['order.reserve'];
            }

            #[InternalHandler('order.reserve')]
            public function reserve(string $orderId): void
            {
            }
        };

        $features = $this->coreFeatures($handlers);

        self::assertTrue($features['Distributed Bus']['enabled']);
        self::assertSame(['order.place → place', 'billing.invoiced → whenInvoiced'], $features['Distributed Bus']['handlers']);
        self::assertTrue($features['Orchestrators']['enabled']);
        self::assertSame(['order.fulfil → fulfil'], $features['Orchestrators']['handlers']);
    }

    public function test_a_service_without_distributed_handlers_or_orchestrators_reports_both_as_disabled(): void
    {
        $features = $this->coreFeatures($this->orders());

        self::assertFalse($features['Distributed Bus']['enabled']);
        self::assertSame([], $features['Distributed Bus']['handlers']);
        self::assertFalse($features['Orchestrators']['enabled']);
    }

    /**
     * @param object[] $extensionObjects
     * @param string[] $classesToResolve
     * @return array<string, array<string, mixed>>
     */
    private function coreFeatures(object $handlers, array $extensionObjects = [], array $classesToResolve = [], ?ServiceConfiguration $serviceConfiguration = null): array
    {
        $pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $orderService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlers::class, ...$classesToResolve],
            containerOrAvailableServices: [$handlers],
            extensionObjects: [
                ...$channels,
                ...$extensionObjects,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                $serviceConfiguration ?? ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$pulse::class],
            containerOrAvailableServices: [$pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $packages = array_column(json_decode($pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR)['packages'], 'features', 'name');

        return array_column($packages['core'], null, 'name');
    }

    private function orders(): object
    {
        return new class () {
            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
            }
        };
    }
}

#[InstantRetry(retryTimes: 5, exceptions: [InvalidArgumentException::class])]
interface PulseInstantRetryCommandBus extends CommandBus
{
}
