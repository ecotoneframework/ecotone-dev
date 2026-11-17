<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Dbal\Gathering\DbalGatheredGroupStore;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheredFlowExecutionRecordingTest extends DbalMessagingTestCase
{
    public function test_a_gathered_flow_over_the_dbal_group_store_is_recorded_part_by_part_and_gathers_as_without_recording(): void
    {
        $gathered = [];
        $lines = [];
        foreach ([false, true] as $recording) {
            [$gathered[], $lines] = $this->gatheredWith($recording);
        }

        self::assertSame($gathered[0], $gathered[1]);
        self::assertSame(
            [
                '↳ outcome: part stored, 1 of 2 in, group waits',
                '↳ outcome: part stored, 2 of 2 in, group released',
            ],
            array_map(fn (int $position): string => $lines[$position + 1], array_keys(array_filter($lines, fn (string $line): bool => preg_match('/^record part \d of 2 in DbalGatheredGroupStore$/', $line) === 1))),
        );
        self::assertContains('↳ outcome: GatheredParts, 2 of 2 parts, original payload array', $lines);
    }

    /**
     * @return array{0: list<array<int, mixed>>, 1: list<string>}
     */
    private function gatheredWith(bool $recording): array
    {
        [$ecotone, $recorder, $handler] = $this->application($recording);

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('workers')->run('summaries');

        return [$handler->gathered, array_map(trim(...), FlowExecution::of($recorder->recordedSpans())->lines())];
    }

    public function test_a_group_store_read_that_fails_fails_the_same_and_leaves_the_next_process_recorded_whole(): void
    {
        $outcomes = [];
        foreach ([false, true] as $recording) {
            [$ecotone, $recorder] = $this->application($recording);
            $ecotone->sendCommandWithRouting('split', [10, 20]);
            $ecotone->run('workers');
            $this->getConnection()->executeStatement('DROP TABLE ' . DbalGatheredGroupStore::PART_TABLE);
            try {
                $ecotone->run('summaries');
                $failure = null;
            } catch (Throwable $thrown) {
                $failure = $thrown::class;
            }
            $ecotone->sendCommandWithRouting('ping');
            $outcomes[] = $failure;
            $lines = FlowExecution::of($recorder->recordedSpans())->lines();
        }
        [$alone, $aloneRecorder] = $this->application(true);
        $alone->sendCommandWithRouting('ping');
        $ping = fn (array $of): array => array_map(fn (string $line): string => (string) preg_replace(['/^process \d+: /', '/\{generated#\d+\}/'], ['process: ', '{generated}'], $line), array_slice($of, (int) array_search('process 1: send command ping, class@anonymous::ping', $of, true)));

        self::assertSame($outcomes[0], $outcomes[1]);
        self::assertNotNull($outcomes[0]);
        self::assertSame(
            $ping(FlowExecution::of($aloneRecorder->recordedSpans())->lines()),
            array_map(fn (string $line): string => (string) preg_replace(['/^process \d+: /', '/\{generated#\d+\}/'], ['process: ', '{generated}'], $line), array_slice($lines, (int) array_key_last(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'process ') && str_contains($line, 'send command ping'))))),
        );
    }

    /**
     * @return array{0: FlowTestSupport, 1: InMemorySpanRecorder, 2: object}
     */
    private function application(bool $recording): array
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('ping')]
            public function ping(): void
            {
            }

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'work.handler')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts->results;
            }
        };
        $recorder = new InMemorySpanRecorder();
        $tracing = TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class);
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN')), InMemorySpanRecorder::class => $recorder],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
                $recording ? $tracing->withExecutionRecording() : $tracing,
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::TRACING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        return [$ecotone, $recorder, $handler];
    }
}
