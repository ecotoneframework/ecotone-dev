<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\MultiTenant\FakeConnectionFactory;

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
        $poller = new class ([
            ['source' => 'tenant_a', 'payload' => 'first'],
            ['source' => 'tenant_b', 'payload' => 'second'],
        ]) {
            public function __construct(private array $pending)
            {
            }

            #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function poll(): ?Message
            {
                if ($this->pending === []) {
                    return null;
                }
                $event = array_shift($this->pending);
                return MessageBuilder::withPayload($event['payload'])
                    ->setHeader('source', $event['source'])
                    ->build();
            }
        };
        $receiver = $this->newReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [$poller::class, $receiver::class]);

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
        $poller = new class ([
            'source' => 'tenant_a',
            'payload' => 'first',
            'tenant' => 'tenant_b',
        ]) {
            public function __construct(private ?array $next)
            {
            }

            #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function poll(): ?Message
            {
                $event = $this->next;
                $this->next = null;
                if ($event === null) {
                    return null;
                }
                return MessageBuilder::withPayload($event['payload'])
                    ->setHeader('source', $event['source'])
                    ->setHeader('tenant', $event['tenant'])
                    ->build();
            }
        };
        $receiver = $this->newReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [$poller::class, $receiver::class]);

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $captured = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($captured);
        $this->assertSame('tenant_b', $captured['tenant'] ?? null, 'Explicit tenant header must win over the resolver expression.');
    }

    public function test_no_tenant_header_when_resolver_attribute_missing(): void
    {
        $poller = new class () {
            #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
            public function poll(): ?Message
            {
                static $emitted = false;
                if ($emitted) {
                    return null;
                }
                $emitted = true;
                return MessageBuilder::withPayload('first')->setHeader('source', 'tenant_a')->build();
            }
        };
        $receiver = $this->newReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [$poller::class, $receiver::class]);

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $captured = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('tenant', $captured, 'Without #[WithTenantResolver], no tenant header should be injected.');
    }

    public function test_no_tenant_header_when_expression_evaluates_to_null(): void
    {
        $poller = new class () {
            #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
            #[WithTenantResolver(expression: "headers['source'] ?? null")]
            public function poll(): ?Message
            {
                static $emitted = false;
                if ($emitted) {
                    return null;
                }
                $emitted = true;
                return MessageBuilder::withPayload('first')->build();
            }
        };
        $receiver = $this->newReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [$poller::class, $receiver::class]);

        $this->pollOnce($ecotone);
        $this->drainProcessing($ecotone);

        $captured = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('tenant', $captured, 'Null expression result must not inject any tenant header.');
    }

    public function test_resolver_interceptor_fires_exactly_once_per_inbound_message(): void
    {
        $poller = new class () {
            #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function poll(): ?Message
            {
                static $emitted = false;
                if ($emitted) {
                    return null;
                }
                $emitted = true;
                return MessageBuilder::withPayload('first')->setHeader('source', 'tenant_a')->build();
            }
        };
        $receiver = $this->newReceiver();
        $counter = new class () {
            private int $count = 0;

            #[Before(pointcut: WithTenantResolver::class)]
            public function increment(): void
            {
                $this->count++;
            }

            #[QueryHandler('counter.invocations')]
            public function invocations(): int
            {
                return $this->count;
            }
        };
        $ecotone = $this->bootstrap(
            [$poller, $receiver, $counter],
            [$poller::class, $receiver::class, $counter::class],
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
        $poller = new class () {
            #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
            #[WithTenantResolver(expression: 'payload')]
            public function poll(): ?Message
            {
                static $emitted = false;
                if ($emitted) {
                    return null;
                }
                $emitted = true;
                return MessageBuilder::withPayload(['source' => 'tenant_a'])->build();
            }
        };
        $receiver = $this->newReceiver();
        $ecotone = $this->bootstrap([$poller, $receiver], [$poller::class, $receiver::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must evaluate to string|int|null');

        $this->pollOnce($ecotone);
    }

    private function newReceiver(): object
    {
        return new class () {
            /** @var array<int, array<string, mixed>> */
            private array $captured = [];

            #[Asynchronous('external_processing')]
            #[CommandHandler('externalEventArrived', endpointId: 'externalEventArrivedEndpoint')]
            public function handle(mixed $payload, #[Headers] array $headers): void
            {
                $this->captured[] = $headers;
            }

            /**
             * @return array<string, mixed>|null
             */
            #[QueryHandler('lastCapturedHeaders')]
            public function lastCapturedHeaders(): ?array
            {
                return array_shift($this->captured);
            }
        };
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
            licenceKey: LicenceTesting::VALID_LICENCE,
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
