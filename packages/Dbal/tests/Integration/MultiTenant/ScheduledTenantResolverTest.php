<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\MultiTenant\FakeConnectionFactory;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventPoller;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventPollerNonScalarExpression;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventPollerNullExpression;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventPollerWithoutResolver;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventReceiver;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\TenantResolverInvocationCounter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ScheduledTenantResolverTest extends TestCase
{
    public function test_resolves_tenant_header_from_inbound_message_via_with_tenant_resolver(): void
    {
        $poller = new ExternalEventPoller([
            ['source' => 'tenant_a', 'payload' => 'first'],
            ['source' => 'tenant_b', 'payload' => 'second'],
        ]);
        $receiver = new ExternalEventReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [ExternalEventPoller::class, ExternalEventReceiver::class]);

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $first = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($first, 'Handler was never invoked - tenant resolution likely blocked the chain.');
        $this->assertSame('tenant_a', $first['tenant'] ?? null, 'Resolver should have derived tenant_a from headers[source].');

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $second = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($second);
        $this->assertSame('tenant_b', $second['tenant'] ?? null, 'Resolver should derive a fresh tenant per inbound message.');
    }

    public function test_explicit_tenant_header_takes_precedence_over_resolver(): void
    {
        $poller = new ExternalEventPoller([
            [
                'source' => 'tenant_a',
                'payload' => 'first',
                'additionalHeaders' => ['tenant' => 'tenant_b'],
            ],
        ]);
        $receiver = new ExternalEventReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [ExternalEventPoller::class, ExternalEventReceiver::class]);

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $captured = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($captured);
        $this->assertSame('tenant_b', $captured['tenant'] ?? null, 'Explicit tenant header must win over the resolver expression.');
    }

    public function test_no_tenant_header_when_resolver_attribute_missing(): void
    {
        $poller = new ExternalEventPollerWithoutResolver([
            ['source' => 'tenant_a', 'payload' => 'first'],
        ]);
        $receiver = new ExternalEventReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [ExternalEventPollerWithoutResolver::class, ExternalEventReceiver::class]);

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $captured = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('tenant', $captured, 'Without #[WithTenantResolver], no tenant header should be injected.');
    }

    public function test_no_tenant_header_when_expression_evaluates_to_null(): void
    {
        $poller = new ExternalEventPollerNullExpression([
            ['payload' => 'first'],
        ]);
        $receiver = new ExternalEventReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [ExternalEventPollerNullExpression::class, ExternalEventReceiver::class]);

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $captured = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('tenant', $captured, 'Null expression result must not inject any tenant header.');
    }

    public function test_resolver_interceptor_fires_exactly_once_per_inbound_message(): void
    {
        $poller = new ExternalEventPoller([
            ['source' => 'tenant_a', 'payload' => 'first'],
        ]);
        $receiver = new ExternalEventReceiver();
        $counter = new TenantResolverInvocationCounter();
        $ecotone = $this->bootstrap(
            [$poller, $receiver, $counter],
            [ExternalEventPoller::class, ExternalEventReceiver::class, TenantResolverInvocationCounter::class],
        );

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $this->assertSame(
            1,
            $ecotone->sendQueryWithRouting('counter.invocations'),
            'Inbound channel adapter must trigger the WithTenantResolver Before interceptor exactly once per message; double-firing would mean propagating handler annotations into endpoint annotations is causing the same pointcut to match twice.'
        );
    }

    public function test_throws_when_resolver_expression_returns_non_scalar(): void
    {
        $poller = new ExternalEventPollerNonScalarExpression([
            ['source' => 'tenant_a'],
        ]);
        $receiver = new ExternalEventReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [ExternalEventPollerNonScalarExpression::class, ExternalEventReceiver::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must evaluate to string|int|null');

        $this->pollOnce($ecotone);
    }

    /**
     * @param object[] $services
     * @param class-string[] $classes
     */
    private function bootstrap(array $services, array $classes): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classes,
            array_merge($services, ['tenant_a_connection' => new FakeConnectionFactory()]),
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    PollingMetadata::create('externalEventPoller')
                        ->setExecutionAmountLimit(1)
                        ->setHandledMessageLimit(1),
                    PollingMetadata::create('external_processing')
                        ->setExecutionAmountLimit(1)
                        ->setHandledMessageLimit(1),
                    MultiTenantConfiguration::createWithDefaultConnection(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_a_connection'],
                        'tenant_a_connection',
                        DbalConnectionFactory::class,
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withTransactionOnAsynchronousEndpoints(false)
                        ->withClearAndFlushObjectManagerOnCommandBus(false)
                        ->withDeduplication(false),
                ]),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('external_processing'),
            ],
        );
    }

    private function pollOnce(FlowTestSupport $ecotone): void
    {
        $ecotone->run('externalEventPoller', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
    }

    private function drainProcessing(FlowTestSupport $ecotone): void
    {
        $ecotone->run('external_processing', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
    }
}
