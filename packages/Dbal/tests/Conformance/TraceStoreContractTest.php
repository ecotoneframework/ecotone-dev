<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Dbal\Database\SpansTableManager;
use Ecotone\Dbal\Database\TracesTableManager;
use Ecotone\Dbal\Tracing\DbalTraceStore;
use Ecotone\Messaging\Scheduling\NativeClock;
use Ecotone\Tracing\InMemoryTraceStore;
use Ecotone\Tracing\TraceStore;
use PHPUnit\Framework\Attributes\DataProvider;
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
final class TraceStoreContractTest extends DbalMessagingTestCase
{
    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_appending_the_same_span_twice_records_it_once(string $implementation): void
    {
        $store = $this->store($implementation);
        $trace = SpanContext::newTrace();
        $root = $this->rootSpan($trace);

        $store->append([$root]);
        $store->append([$root]);

        self::assertTrue($store->progressOf($trace->traceId)->isCompleted());
        self::assertTrue($store->markCompletedIfDone($trace->traceId, new DatePoint()));
        self::assertFalse($store->markCompletedIfDone($trace->traceId, new DatePoint()));
    }

    #[DataProvider('implementations')]
    public function test_a_span_appended_after_completion_reopens_the_trace_so_it_can_complete_and_notify_again(string $implementation): void
    {
        $store = $this->store($implementation);
        $trace = SpanContext::newTrace();
        $store->append([$this->rootSpan($trace)]);
        self::assertTrue($store->markCompletedIfDone($trace->traceId, new DatePoint()));
        $store->markNotified($trace->traceId);

        $store->append([$this->rootSpan($trace)]);

        self::assertTrue($store->markCompletedIfDone($trace->traceId, new DatePoint()));
        self::assertSame([$trace->traceId], $store->completedButNotNotifiedBefore((new DatePoint())->modify('+1 minute'), 10));
    }

    private function store(string $implementation): TraceStore
    {
        if ($implementation === 'in-memory') {
            return new InMemoryTraceStore();
        }
        $traces = new TracesTableManager(TracesTableManager::DEFAULT_TABLE, true);
        $spans = new SpansTableManager(SpansTableManager::DEFAULT_TABLE, true);
        $connection = $this->getConnection();
        foreach ([$traces, $spans] as $tableManager) {
            if (! $tableManager->isInitialized($connection)) {
                $tableManager->createTable($connection);
            }
        }

        return new DbalTraceStore($this->getConnectionFactory(), $traces, $spans, new NativeClock());
    }

    private function rootSpan(SpanContext $trace): Span
    {
        return new Span($trace->traceId, $trace->newChild()->spanId, null, 'send command', SpanKind::Server, [SpanAttribute::TRACE_ROOT => true], SpanStatus::Ok, null, [], new DatePoint(), new DatePoint());
    }
}
