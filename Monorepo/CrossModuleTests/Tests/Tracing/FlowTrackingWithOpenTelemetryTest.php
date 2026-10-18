<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Tracing;

use ArrayObject;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\OnTraceCompleted;
use Ecotone\Api\Dbal\Tracing\TraceCompleted;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Test\LicenceTesting;
use Monorepo\CrossModuleTests\Tests\MessagingTestCase;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\OpenTelemetry\Integration\TracingTestCase;

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
final class FlowTrackingWithOpenTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        MessagingTestCase::cleanUpDbal();
    }

    public function test_one_trace_is_exported_to_open_telemetry_and_stored_in_the_database_across_an_asynchronous_dbal_channel(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $subscriber = $this->traceCompletedSubscriber();
        $ecotone = $this->bootstrap($exporter, [$this->users(), $this->asynchronousNotifier(), $subscriber]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        self::assertSame(
            ['process class@anonymous::register', 'route user.notifier', 'send command', 'send event', 'send notifications'],
            self::exportedSpanNamesOf($exporter, $trace->traceId),
        );
        self::assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.registration'));
        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
        self::assertSame([], $subscriber->completedTraces);

        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            ['process class@anonymous::notify', 'process class@anonymous::register', 'process class@anonymous::whenCompleted', 'receive notifications', 'route user.notifier', 'send command', 'send event', 'send notifications'],
            self::exportedSpanNamesOf($exporter, $trace->traceId),
        );
        self::assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));
        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
        self::assertSame([$trace->traceId], $subscriber->completedTraces);
    }

    public function test_a_second_application_on_the_same_database_sees_the_completion_the_first_one_stored(): void
    {
        $producer = $this->bootstrap(new InMemoryExporter(new ArrayObject()), [$this->users()]);
        $observer = $this->bootstrap(new InMemoryExporter(new ArrayObject()), [$this->users()]);
        $trace = SpanContext::newTrace();

        $producer->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        self::assertTrue($this->completion($observer)->isCompleted($trace->traceId));
    }

    /**
     * @return string[]
     */
    private static function exportedSpanNamesOf(InMemoryExporter $exporter, string $traceId): array
    {
        $names = [];
        /** @var SpanDataInterface $span */
        foreach ($exporter->getSpans() as $span) {
            if ($span->getContext()->getTraceId() !== $traceId || str_starts_with($span->getName(), 'process MessageHeadersPropagatorInterceptor::')) {
                continue;
            }
            $names[] = preg_replace('/^process class@anonymous\S*::/', 'process class@anonymous::', $span->getName());
        }
        sort($names);

        return $names;
    }

    /**
     * @param object[] $services
     */
    private function bootstrap(InMemoryExporter $exporter, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            array_map(fn (object $service): string => $service::class, $services),
            [
                ...$services,
                DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone'),
                TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter),
            ],
            [
                FlowTrackingConfiguration::createWithDefaults(),
                DbalBackedMessageChannelBuilder::create('notifications'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::TRACING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
    }

    private function completion(FlowTestSupport $ecotone): TracingGateway
    {
        return $ecotone->getGateway(TracingGateway::class);
    }

    private function users(): object
    {
        return new class () {
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('user.registered', $userId);
            }
        };
    }

    private function asynchronousNotifier(): object
    {
        return new class () {
            #[Asynchronous('notifications')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
            }
        };
    }

    private function traceCompletedSubscriber(): object
    {
        return new class () {
            /** @var string[] */
            public array $completedTraces = [];

            #[OnTraceCompleted]
            public function whenCompleted(TraceCompleted $event): void
            {
                $this->completedTraces[] = $event->tracingId;
            }
        };
    }
}
