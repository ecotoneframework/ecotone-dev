<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Tracing;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Test\LicenceTesting;
use Fiber;
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
final class InterleavedFlowTrackingTest extends DbalMessagingTestCase
{
    public function test_flows_of_interleaved_executions_are_stored_complete_and_each_with_its_own_steps(): void
    {
        $orders = new class () {
            #[CommandHandler('order.place', endpointId: 'order.placement')]
            public function place(string $orderId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $orderId);
                Fiber::suspend();
                $eventBus->publishWithRouting('order.paid', $orderId);
            }

            #[EventHandler('order.placed', endpointId: 'order.confirmation')]
            public function confirm(string $orderId): void
            {
            }

            #[EventHandler('order.paid', endpointId: 'order.shipping')]
            public function ship(string $orderId): void
            {
            }

            #[CommandHandler('invoice.issue', endpointId: 'invoice.issuing')]
            public function issue(string $invoiceId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('invoice.issued', $invoiceId);
            }

            #[EventHandler('invoice.issued', endpointId: 'invoice.mailing')]
            public function mail(string $invoiceId): void
            {
            }
        };
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$orders::class],
            containerOrAvailableServices: [$orders, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            extensionObjects: [FlowTrackingConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
        $orderTrace = SpanContext::newTrace();
        $invoiceTrace = SpanContext::newTrace();

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('order.place', 'order-1', metadata: [MessageHeaders::TRACEPARENT => $orderTrace->toTraceparent()]));
        $interleaved = new Fiber(fn () => $ecotone->sendCommandWithRouting('invoice.issue', 'invoice-1', metadata: [MessageHeaders::TRACEPARENT => $invoiceTrace->toTraceparent()]));
        $suspended->start();
        $interleaved->start();
        $invoiceFlowCompletedWhileOrderSuspended = $ecotone->getGateway(TracingGateway::class)->isCompletedFor($invoiceTrace->traceId);
        $orderFlowCompletedWhileSuspended = $ecotone->getGateway(TracingGateway::class)->isCompletedFor($orderTrace->traceId);
        $suspended->resume();

        $this->assertTrue($interleaved->isTerminated());
        $this->assertTrue($suspended->isTerminated());
        $this->assertTrue($invoiceFlowCompletedWhileOrderSuspended);
        $this->assertFalse($orderFlowCompletedWhileSuspended);
        $orderFlow = $ecotone->getGateway(TracingGateway::class)->fetchFlow($orderTrace->traceId);
        $invoiceFlow = $ecotone->getGateway(TracingGateway::class)->fetchFlow($invoiceTrace->traceId);
        $this->assertTrue($orderFlow->isCompleted);
        $this->assertTrue($invoiceFlow->isCompleted);
        $this->assertEqualsCanonicalizing(['order.placement', 'order.confirmation', 'order.shipping'], $orderFlow->reached);
        $this->assertEqualsCanonicalizing(['invoice.issuing', 'invoice.mailing'], $invoiceFlow->reached);
        $this->assertSame([$orderTrace->traceId], array_values(array_unique(array_map(fn (Span $step): string => $step->traceId, $orderFlow->steps))));
        $this->assertSame([$invoiceTrace->traceId], array_values(array_unique(array_map(fn (Span $step): string => $step->traceId, $invoiceFlow->steps))));
    }
}
