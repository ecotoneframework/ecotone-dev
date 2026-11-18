<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Execution;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresPhpExtension('openswoole')]
final class CoroutineExecutionsTest extends TestCase
{
    public function test_coroutine_started_by_a_handler_propagates_the_headers_of_the_message_being_handled(): void
    {
        $ecotone = $this->bootstrapParcels();

        $this->runCoroutines(
            fn () => $ecotone->sendCommandWithRouting('parcel.sendFromCoroutine', ['parcelId' => 'first', 'pauseSeconds' => 0.05], metadata: ['tenant' => 'tenant-of-first']),
            fn () => $ecotone->sendCommandWithRouting('parcel.sendFromCoroutine', ['parcelId' => 'second'], metadata: ['tenant' => 'tenant-of-second']),
        );

        $this->assertEquals(
            ['first' => 'tenant-of-first', 'second' => 'tenant-of-second'],
            $ecotone->sendQueryWithRouting('parcel.tenantSeenByEventHandler')
        );
    }

    public function test_coroutine_started_by_a_coroutine_a_handler_started_propagates_the_headers_of_the_message_being_handled(): void
    {
        $ecotone = $this->bootstrapParcels();

        $this->runCoroutines(
            fn () => $ecotone->sendCommandWithRouting('parcel.sendFromCoroutineOfCoroutine', ['parcelId' => 'first'], metadata: ['tenant' => 'tenant-of-first']),
        );

        $this->assertSame(['first' => 'tenant-of-first'], $ecotone->sendQueryWithRouting('parcel.tenantSeenByEventHandler'));
    }

    public function test_coroutines_a_handler_runs_outside_of_any_coroutine_propagate_the_headers_of_the_message_being_handled(): void
    {
        $ecotone = $this->bootstrapParcels();

        $ecotone->sendCommandWithRouting('parcel.sendFromCoroutinesRunByHandler', ['parcelId' => 'first'], metadata: ['tenant' => 'tenant-of-first']);

        $this->assertSame(['first' => 'tenant-of-first'], $ecotone->sendQueryWithRouting('parcel.tenantSeenByEventHandler'));
    }

    public function test_coroutine_outliving_its_handler_does_not_propagate_the_headers_of_the_next_message(): void
    {
        $ecotone = $this->bootstrapParcels();

        $this->runCoroutines(
            function () use ($ecotone): void {
                $ecotone->sendCommandWithRouting('parcel.sendFromCoroutineLeftBehind', ['parcelId' => 'left behind', 'pauseSeconds' => 0.05], metadata: ['tenant' => 'tenant-of-left-behind']);
                $ecotone->sendCommandWithRouting('parcel.pause', ['pauseSeconds' => 0.2], metadata: ['tenant' => 'tenant-of-next-message']);
            },
        );

        $this->assertSame(['left behind' => null], $ecotone->sendQueryWithRouting('parcel.tenantSeenByEventHandler'));
    }

    private function runCoroutines(callable ...$coroutines): void
    {
        $failure = null;
        Runtime::enableCoroutine(Runtime::HOOK_ALL);
        try {
            Coroutine::run(static function () use ($coroutines, &$failure): void {
                foreach ($coroutines as $coroutine) {
                    Coroutine::create(static function () use ($coroutine, &$failure): void {
                        try {
                            $coroutine();
                        } catch (Throwable $exception) {
                            $failure ??= $exception;
                        }
                    });
                }
            });
        } finally {
            Runtime::enableCoroutine(0);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function bootstrapParcels(): FlowTestSupport
    {
        $parcels = new class () {
            private array $tenantSeenByEventHandler = [];

            #[CommandHandler('parcel.sendFromCoroutine')]
            public function sendFromCoroutine(array $command, EventBus $eventBus): void
            {
                $sent = new Coroutine\Channel(1);
                Coroutine::create(static function () use ($command, $eventBus, $sent): void {
                    usleep((int) (($command['pauseSeconds'] ?? 0) * 1_000_000));
                    $eventBus->publish(new ParcelWasSent($command['parcelId']));
                    $sent->push(true);
                });
                $sent->pop();
            }

            #[CommandHandler('parcel.sendFromCoroutineOfCoroutine')]
            public function sendFromCoroutineOfCoroutine(array $command, EventBus $eventBus): void
            {
                $sent = new Coroutine\Channel(1);
                Coroutine::create(static function () use ($command, $eventBus, $sent): void {
                    $sentByCoroutineOfCoroutine = new Coroutine\Channel(1);
                    Coroutine::create(static function () use ($command, $eventBus, $sentByCoroutineOfCoroutine): void {
                        usleep(10_000);
                        $eventBus->publish(new ParcelWasSent($command['parcelId']));
                        $sentByCoroutineOfCoroutine->push(true);
                    });
                    $sentByCoroutineOfCoroutine->pop();
                    $sent->push(true);
                });
                $sent->pop();
            }

            #[CommandHandler('parcel.sendFromCoroutinesRunByHandler')]
            public function sendFromCoroutinesRunByHandler(array $command, EventBus $eventBus): void
            {
                Coroutine::run(static function () use ($command, $eventBus): void {
                    Coroutine::create(static fn () => $eventBus->publish(new ParcelWasSent($command['parcelId'])));
                });
            }

            #[CommandHandler('parcel.sendFromCoroutineLeftBehind')]
            public function sendFromCoroutineLeftBehind(array $command, EventBus $eventBus): void
            {
                Coroutine::create(static function () use ($command, $eventBus): void {
                    usleep((int) ($command['pauseSeconds'] * 1_000_000));
                    $eventBus->publish(new ParcelWasSent($command['parcelId']));
                });
            }

            #[CommandHandler('parcel.pause')]
            public function pause(array $command): void
            {
                usleep((int) ($command['pauseSeconds'] * 1_000_000));
            }

            #[EventHandler]
            public function when(ParcelWasSent $event, #[Header('tenant')] ?string $tenant = null): void
            {
                $this->tenantSeenByEventHandler[$event->parcelId] = $tenant;
            }

            #[QueryHandler('parcel.tenantSeenByEventHandler')]
            public function tenantSeenByEventHandler(): array
            {
                return $this->tenantSeenByEventHandler;
            }
        };

        return EcotoneLite::bootstrapFlowTesting([$parcels::class], [$parcels]);
    }
}

final class ParcelWasSent
{
    public function __construct(public string $parcelId)
    {
    }
}
