<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\OnFlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Test\LicenceTesting;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use PHPUnit\Framework\TestCase;

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
final class FlowTrackingWithNoopProviderTest extends TestCase
{
    public function test_a_provider_that_records_nothing_still_gives_every_stored_span_its_own_id(): void
    {
        $users = new class () {
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('user.registered', $userId);
            }

            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
            }
        };
        $subscriber = new class () {
            /** @var string[] */
            public array $spanIds = [];

            #[OnFlowStepCompleted]
            public function whenEnded(FlowStepCompleted $event): void
            {
                $this->spanIds[] = $event->step->spanId;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$users::class, $subscriber::class],
            [$users, $subscriber, TracerProviderInterface::class => new NoopTracerProvider()],
            [FlowTrackingConfiguration::createWithDefaults(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE, ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertCount(count($subscriber->spanIds), array_unique($subscriber->spanIds));
        $this->assertGreaterThan(3, count($subscriber->spanIds));
        $this->assertTrue($ecotone->getGateway(TracingGateway::class)->isCompleted($trace->traceId));
    }
}
