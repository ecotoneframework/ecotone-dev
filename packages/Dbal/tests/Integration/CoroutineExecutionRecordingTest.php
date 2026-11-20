<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use PHPUnit\Framework\Attributes\RequiresMethod;
use RuntimeException;
use Swoole\Coroutine;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionRecordingTest extends DbalMessagingTestCase
{
    public function test_messages_of_a_database_channel_consumed_on_several_coroutines_with_one_sent_to_the_dead_letter_are_recorded_traced_and_handled_as_on_one_coroutine(): void
    {
        $alone = $this->consumedOn(coroutines: 1);
        $atOnce = $this->consumedOn(coroutines: 3);

        self::assertCount(6, $atOnce['processes']);
        self::assertCount(6, $atOnce['traces']);
        self::assertSame($alone['processes'], $atOnce['processes']);
        self::assertSame($alone['traces'], $atOnce['traces']);
        self::assertSame(['shipped' => ['first', 'second', 'third', 'fourth', 'fifth'], 'deadLetters' => 1], $alone['outcome']);
        self::assertSame($alone['outcome'], $atOnce['outcome']);
        self::assertSame(3, $atOnce['mostShippedAtOnce']);
    }

    /**
     * @return array{processes: list<array{string, list<array{int, string, list<string>}>}>, traces: list<list<array{string, ?string, string}>>, outcome: array<string, mixed>, mostShippedAtOnce: int}
     */
    private function consumedOn(int $coroutines): array
    {
        $recorder = new InMemorySpanRecorder();
        $ecotone = $this->shipments(recorder: $recorder, coroutines: $coroutines);
        $ecotone->getGateway(DeadLetterGateway::class)->deleteAll();
        foreach (['first', 'second', 'refused', 'third', 'fourth', 'fifth'] as $parcel) {
            $ecotone->sendCommandWithRouting('shipment.ship', $parcel);
        }
        $ecotone->popExecutionRecording();
        $recorder->clear();

        $ecotone->run('recorded_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        return [
            'processes' => self::processesOf($ecotone),
            'traces' => self::traceShapesOf($recorder->recordedSpans()),
            'outcome' => [
                'shipped' => $ecotone->sendQueryWithRouting('shipment.shipped'),
                'deadLetters' => $ecotone->getGateway(DeadLetterGateway::class)->count(),
            ],
            'mostShippedAtOnce' => $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'),
        ];
    }

    /**
     * @return list<array{string, list<array{int, string, list<string>}>}>
     */
    private static function processesOf(FlowTestSupport $ecotone): array
    {
        $processes = [];
        foreach ($ecotone->popExecutionRecording()->processes as $process) {
            $processes[] = [
                (string) preg_replace('/ \((?:part )?\d+ of \d+\)$/', '', $process->title),
                array_map(fn ($step): array => [$step->depth, $step->label, array_map(fn ($hint): string => $hint->key . ': ' . preg_replace('/process \d+/', 'process #', $hint->value), $step->hints)], $process->steps),
            ];
        }
        usort($processes, fn (array $first, array $second): int => serialize($first) <=> serialize($second));

        return $processes;
    }

    /**
     * @param list<Span> $spans
     * @return list<list<array{string, ?string, string}>>
     */
    private static function traceShapesOf(array $spans): array
    {
        $byId = [];
        foreach ($spans as $span) {
            $byId[$span->spanId] = $span;
        }
        $traces = [];
        foreach ($spans as $span) {
            $traces[$span->traceId][] = [$span->name, isset($byId[(string) $span->parentSpanId]) ? $byId[(string) $span->parentSpanId]->name : null, $span->status->name];
        }
        $shapes = array_map(function (array $trace): array {
            sort($trace);

            return $trace;
        }, array_values($traces));
        usort($shapes, fn (array $first, array $second): int => serialize($first) <=> serialize($second));

        return $shapes;
    }

    private function shipments(InMemorySpanRecorder $recorder, int $coroutines): FlowTestSupport
    {
        $shipments = new class () {
            private array $shipped = [];
            private int $shippedAtOnce = 0;
            private int $mostShippedAtOnce = 0;

            #[Asynchronous('recorded_shipments')]
            #[CommandHandler('shipment.ship', endpointId: 'shipment.ship.endpoint')]
            public function ship(string $parcel): void
            {
                $this->shippedAtOnce++;
                $this->mostShippedAtOnce = max($this->mostShippedAtOnce, $this->shippedAtOnce);
                try {
                    usleep(50_000);
                    if ($parcel === 'refused') {
                        throw new RuntimeException('Parcel was refused');
                    }
                    $this->shipped[] = $parcel;
                } finally {
                    $this->shippedAtOnce--;
                }
            }

            #[QueryHandler('shipment.shipped')]
            public function shipped(): array
            {
                $order = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5];
                $shipped = $this->shipped;
                usort($shipped, fn (string $first, string $second): int => $order[$first] <=> $order[$second]);

                return $shipped;
            }

            #[QueryHandler('shipment.mostShippedAtOnce')]
            public function mostShippedAtOnce(): int
            {
                return $this->mostShippedAtOnce;
            }
        };

        return $this->bootstrapFlowTesting(
            [$shipments::class],
            [$shipments, DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => self::mysqlDsn(), 'connection_pool_size' => 10]), InMemorySpanRecorder::class => $recorder],
            [
                DbalConfiguration::createWithDefaults(),
                DbalBackedMessageChannelBuilder::create('recorded_shipments'),
                PollingMetadata::create('recorded_shipments')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL)->withCoroutines($coroutines),
                InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
                TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel(TracingLevel::Framework),
            ],
            [ModulePackageList::DBAL_PACKAGE, ModulePackageList::TRACING_PACKAGE],
        );
    }

    private static function mysqlDsn(): string
    {
        foreach ([getenv('DATABASE_DSN'), getenv('SECONDARY_DATABASE_DSN')] as $dsn) {
            if (is_string($dsn) && str_starts_with($dsn, 'mysql')) {
                return $dsn;
            }
        }

        throw new RuntimeException('The coroutine tests need a mysql database in DATABASE_DSN or SECONDARY_DATABASE_DSN.');
    }
}
