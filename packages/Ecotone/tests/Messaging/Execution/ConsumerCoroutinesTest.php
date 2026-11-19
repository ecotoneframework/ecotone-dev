<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Execution;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

use Swoole\Runtime;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class ConsumerCoroutinesTest extends TestCase
{
    public function test_consumer_handles_as_many_messages_at_once_as_the_coroutines_passed_when_running_it(): void
    {
        $ecotone = $this->bootstrapShipments();
        $this->shipParcels($ecotone, 8);

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(4));

        $this->assertSame(8, $ecotone->sendQueryWithRouting('shipment.shipped'));
        $this->assertSame(4, $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'));
    }

    public function test_consumer_handles_as_many_messages_at_once_as_the_coroutines_of_its_polling_metadata(): void
    {
        $ecotone = $this->bootstrapShipments(PollingMetadata::create('shipments')->withCoroutines(3));
        $this->shipParcels($ecotone, 6);

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(6, $ecotone->sendQueryWithRouting('shipment.shipped'));
        $this->assertSame(3, $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'));
    }

    public function test_coroutines_passed_when_running_the_consumer_replace_the_ones_of_its_polling_metadata(): void
    {
        $ecotone = $this->bootstrapShipments(PollingMetadata::create('shipments')->withCoroutines(3));
        $this->shipParcels($ecotone, 4);

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(2));

        $this->assertSame(4, $ecotone->sendQueryWithRouting('shipment.shipped'));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'));
    }

    public function test_consumer_run_by_the_console_command_handles_as_many_messages_at_once_as_its_coroutines_option(): void
    {
        $ecotone = $this->bootstrapShipments();
        $this->shipParcels($ecotone, 6);

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'shipments', 'coroutines' => 3, 'finishWhenNoMessages' => true]);

        $this->assertSame(6, $ecotone->sendQueryWithRouting('shipment.shipped'));
        $this->assertSame(3, $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'));
    }

    public function test_consumer_started_inside_a_coroutine_consumes_on_coroutines_of_its_own(): void
    {
        $ecotone = $this->bootstrapShipments();
        $this->shipParcels($ecotone, 4);

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            $scheduler = new Coroutine\Scheduler();
            $scheduler->add(static function () use ($ecotone): void {
                $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(2));
            });
            $scheduler->start();
        } finally {
            Runtime::enableCoroutine(0);
        }

        $this->assertSame(4, $ecotone->sendQueryWithRouting('shipment.shipped'));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'));
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_coroutines_the_application_runs_after_a_consumer_still_wait_without_stopping_each_other(): void
    {
        $consumedBefore = $this->bootstrapShipments();
        $this->shipParcels($consumedBefore, 2);
        $consumedBefore->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(2));
        $ecotone = $this->bootstrapShipments();
        $this->shipParcels($ecotone, 4);

        run(static function () use ($ecotone): void {
            $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(2));
        });

        $this->assertSame(4, $ecotone->sendQueryWithRouting('shipment.shipped'));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'));
    }

    public function test_consumer_without_coroutines_handles_one_message_at_a_time(): void
    {
        $ecotone = $this->bootstrapShipments();
        $this->shipParcels($ecotone, 3);

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(3, $ecotone->sendQueryWithRouting('shipment.shipped'));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'));
    }

    public function test_failure_of_one_coroutine_stops_the_others_and_is_thrown_by_the_consumer(): void
    {
        $ecotone = $this->bootstrapShipments();
        $ecotone->sendCommandWithRouting('shipment.ship', ['parcelId' => 'lost']);

        $failure = null;
        try {
            $ecotone->run(
                'shipments',
                ExecutionPollingMetadata::createWithDefaults()
                    ->withCoroutines(3)
                    ->withStopOnError(true)
                    ->withFinishWhenNoMessages(false)
                    ->withExecutionTimeLimitInMilliseconds(60_000)
            );
        } catch (RuntimeException $failure) {
        }

        $this->assertSame('Parcel lost cannot be shipped', $failure?->getMessage());
    }

    private function shipParcels(FlowTestSupport $ecotone, int $parcels): void
    {
        for ($parcel = 1; $parcel <= $parcels; $parcel++) {
            $ecotone->sendCommandWithRouting('shipment.ship', ['parcelId' => "parcel-{$parcel}"]);
        }
    }

    private function bootstrapShipments(?PollingMetadata $pollingMetadata = null): FlowTestSupport
    {
        $shipments = new class () {
            private int $shipped = 0;
            private int $shippedAtOnce = 0;
            private int $mostShippedAtOnce = 0;

            #[Asynchronous('shipments')]
            #[CommandHandler('shipment.ship', endpointId: 'shipment.ship.endpoint')]
            public function ship(array $command): void
            {
                if ($command['parcelId'] === 'lost') {
                    throw new RuntimeException('Parcel lost cannot be shipped');
                }

                $this->shippedAtOnce++;
                $this->mostShippedAtOnce = max($this->mostShippedAtOnce, $this->shippedAtOnce);
                usleep(50_000);
                $this->shippedAtOnce--;
                $this->shipped++;
            }

            #[QueryHandler('shipment.shipped')]
            public function shipped(): int
            {
                return $this->shipped;
            }

            #[QueryHandler('shipment.mostShippedAtOnce')]
            public function mostShippedAtOnce(): int
            {
                return $this->mostShippedAtOnce;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$shipments::class],
            [$shipments],
            extensionObjects: array_values(array_filter([SimpleMessageChannelBuilder::createQueueChannel('shipments'), $pollingMetadata])),
        );
    }
}
