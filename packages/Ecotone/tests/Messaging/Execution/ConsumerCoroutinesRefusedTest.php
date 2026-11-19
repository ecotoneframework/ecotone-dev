<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Execution;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsumerCoroutinesRefusedTest extends TestCase
{
    public function test_consuming_on_coroutines_passed_when_running_the_consumer_is_refused_without_a_coroutine_runtime(): void
    {
        $this->requireNoCoroutineRuntime();
        $ecotone = $this->bootstrapShipments();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Consumer `shipments` is set to consume on 4 coroutines, but this PHP has neither the openswoole nor the swoole extension loaded, so there is nothing to run coroutines on. '
            . 'Install one of the extensions for the PHP that runs the consumer, or consume one message at a time: leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines(). '
            . 'To handle more messages at once without coroutines, start more processes of this consumer.'
        );

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(4));
    }

    public function test_consuming_on_coroutines_of_the_polling_metadata_is_refused_without_a_coroutine_runtime(): void
    {
        $this->requireNoCoroutineRuntime();
        $ecotone = $this->bootstrapShipments(PollingMetadata::create('shipments')->withCoroutines(2));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Consumer `shipments` is set to consume on 2 coroutines, but this PHP has neither the openswoole nor the swoole extension loaded');

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }

    public function test_consuming_on_coroutines_of_the_console_command_is_refused_without_a_coroutine_runtime(): void
    {
        $this->requireNoCoroutineRuntime();
        $ecotone = $this->bootstrapShipments();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Consumer `shipments` is set to consume on 3 coroutines, but this PHP has neither the openswoole nor the swoole extension loaded');

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'shipments', 'coroutines' => 3, 'finishWhenNoMessages' => true]);
    }

    public function test_one_coroutine_consumes_without_a_coroutine_runtime(): void
    {
        $ecotone = $this->bootstrapShipments(PollingMetadata::create('shipments')->withCoroutines(1));
        $ecotone->sendCommandWithRouting('shipment.ship');

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(1));

        $this->assertSame(1, $ecotone->sendQueryWithRouting('shipment.shipped'));
    }

    public function test_polling_metadata_with_less_than_one_coroutine_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Consumer `shipments` cannot consume on 0 coroutines: a consumer needs at least one. '
            . 'Pass a positive number to PollingMetadata::withCoroutines(), or leave it out to consume one message at a time.'
        );

        PollingMetadata::create('shipments')->withCoroutines(0);
    }

    public function test_execution_polling_metadata_with_less_than_one_coroutine_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'A consumer cannot consume on -1 coroutines: it needs at least one. '
            . 'Pass a positive number to ExecutionPollingMetadata::withCoroutines() or to the `coroutines` option of ecotone:run, or leave it out to consume one message at a time.'
        );

        ExecutionPollingMetadata::createWithDefaults()->withCoroutines(-1);
    }

    public function test_scheduled_endpoint_is_refused_to_run_on_coroutines(): void
    {
        $reports = new class () {
            #[Scheduled(requestChannelName: 'report.send', endpointId: 'report.schedule')]
            public function schedule(): string
            {
                return 'daily';
            }

            #[CommandHandler('report.send')]
            public function send(string $report): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$reports::class], [$reports]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Endpoint `report.schedule` is set to run on 2 coroutines, but it runs on a schedule instead of consuming a channel, so every coroutine would run each scheduled execution again. '
            . 'Leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines() for this endpoint. '
            . 'To do its work on coroutines, let it send messages to an #[Asynchronous] handler and run that channel\'s consumer on coroutines.'
        );

        $ecotone->run('report.schedule', ExecutionPollingMetadata::createWithTestingSetup()->withCoroutines(2));
    }

    private function requireNoCoroutineRuntime(): void
    {
        if (extension_loaded('openswoole') || extension_loaded('swoole')) {
            $this->markTestSkipped('This PHP can run coroutines');
        }
    }

    private function bootstrapShipments(?PollingMetadata $pollingMetadata = null): FlowTestSupport
    {
        $shipments = new class () {
            private int $shipped = 0;

            #[Asynchronous('shipments')]
            #[CommandHandler('shipment.ship', endpointId: 'shipment.ship.endpoint')]
            public function ship(): void
            {
                $this->shipped++;
            }

            #[QueryHandler('shipment.shipped')]
            public function shipped(): int
            {
                return $this->shipped;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$shipments::class],
            [$shipments],
            extensionObjects: array_values(array_filter([SimpleMessageChannelBuilder::createQueueChannel('shipments'), $pollingMetadata])),
        );
    }
}
