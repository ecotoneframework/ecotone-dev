<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\LazyInMemoryContainer;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConfiguration;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Support\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Messaging\Fixture\Channel\FakeContextWithMessages;
use Test\Ecotone\Messaging\Fixture\Channel\FakeMessageChannelWithConnectionFactoryBuilder;
use Test\Ecotone\Messaging\Fixture\Config\FakeConnectionFactory;
use Test\Ecotone\Messaging\Fixture\Handler\SuccessServiceActivator;
use Test\Ecotone\Modelling\Fixture\Collector\BetService;

final class MultiTenantConnectionFactoryTest extends TestCase
{
    public function test_using_default_connection_factory_for_sending_when_tenant_not_mapped()
    {
        $notExpectedContext = new FakeContextWithMessages();
        $expectedContext = new FakeContextWithMessages();
        $connections = [
            'tenant_a_connection' => new FakeConnectionFactory($notExpectedContext),
            'default_tenant_connection' => new FakeConnectionFactory($expectedContext)
        ];

        $ecotoneLite = $this->boostrapEcotone('tenant', $connections, defaultConnectionName: 'default_tenant_connection');
        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false, metadata: ['tenant' => 'unknown']);

        $this->assertNotNull($expectedContext->receive());;
    }

    public function test_using_default_mapped_connection_factory_for_sending()
    {
        $notExpectedContext = new FakeContextWithMessages();
        $expectedContext = new FakeContextWithMessages();
        $connections = [
            'tenant_a_connection' => new FakeConnectionFactory($notExpectedContext),
            'tenant_b_connection' => new FakeConnectionFactory($expectedContext)
        ];

        $ecotoneLite = $this->boostrapEcotone('tenant', $connections, [
            'tenant_a' => 'tenant_a_connection',
            'tenant_b' => 'tenant_b_connection'
        ]);
        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false, metadata: ['tenant' => 'tenant_b']);

        $this->assertNotNull($expectedContext->receive());;
    }

    public function test_throwing_exception_when_no_tenant_header_found()
    {
        $notExpectedContext = new FakeContextWithMessages();
        $expectedContext = new FakeContextWithMessages();
        $connections = [
            'tenant_a_connection' => new FakeConnectionFactory($notExpectedContext),
            'tenant_b_connection' => new FakeConnectionFactory($expectedContext)
        ];

        $ecotoneLite = $this->boostrapEcotone('tenant', $connections, [
            'tenant_a' => 'tenant_a_connection',
            'tenant_b' => 'tenant_b_connection'
        ]);

        $this->expectException(InvalidArgumentException::class);

        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false);
    }

    public function test_throwing_exception_when_tenant_can_not_be_mapped_and_no_default_channel_provided()
    {
        $notExpectedContext = new FakeContextWithMessages();
        $expectedContext = new FakeContextWithMessages();
        $connections = [
            'tenant_a_connection' => new FakeConnectionFactory($notExpectedContext),
            'tenant_b_connection' => new FakeConnectionFactory($expectedContext)
        ];

        $ecotoneLite = $this->boostrapEcotone('tenant', $connections, [
            'tenant_a' => 'tenant_a_connection',
            'tenant_b' => 'tenant_b_connection'
        ]);

        $this->expectException(InvalidArgumentException::class);

        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false, metadata: ['tenant' => 'tenant_x']);
    }

    public function test_round_robin_as_default_for_multi_tenant_connection_while_fetching_messages()
    {
        $connections = [
            'tenant_a_connection' => new FakeConnectionFactory(new FakeContextWithMessages()),
            'tenant_b_connection' => new FakeConnectionFactory(new FakeContextWithMessages())
        ];

        $ecotoneLite = $this->boostrapEcotone('tenant', $connections, [
            'tenant_a' => 'tenant_a_connection',
            'tenant_b' => 'tenant_b_connection'
        ]);


        /** Sending two Messages to tenant A */
        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false, metadata: ['tenant' => 'tenant_a']);
        /** Fetching Tenant A */
        $ecotoneLite->run('bets', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $usedConnectionContext = $ecotoneLite->sendQueryWithRouting('getLastBetHeaders')['tenant'];
        $this->assertSame('tenant_a', $usedConnectionContext);
        /** Fetching Tenant B */
        $ecotoneLite->run('bets', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $this->assertNull($ecotoneLite->sendQueryWithRouting('getLastBetHeaders'));;
        /** Fetching Tenant A - Bet Placed Event was propagated to Tenant A */
        $ecotoneLite->run('bets', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $usedConnectionContext = $ecotoneLite->sendQueryWithRouting('getLastBetHeaders')['tenant'];
        $this->assertSame('tenant_a', $usedConnectionContext);

        /** Sending to tenant B */
        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false, metadata: ['tenant' => 'tenant_b']);
        /** Fetching Tenant B */
        $ecotoneLite->run('bets', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $usedConnectionContext = $ecotoneLite->sendQueryWithRouting('getLastBetHeaders')['tenant'];
        $this->assertSame('tenant_b', $usedConnectionContext);

        /** Fetching Tenant A - Bet Won Event was propagated to Tenant A */
        $ecotoneLite->run('bets', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $usedConnectionContext = $ecotoneLite->sendQueryWithRouting('getLastBetHeaders')['tenant'];
        $this->assertSame('tenant_a', $usedConnectionContext);
    }

    /**
     * @param array<string, FakeConnectionFactory> $connections
     */
    private function boostrapEcotone(
        string $tenantHeaderName,
        array $connections,
        array $tenantConnectionMapping = [],
        ?string $defaultConnectionName = null,
    ): \Ecotone\Lite\Test\FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [BetService::class],
            array_merge([new BetService()], $connections),
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    PollingMetadata::create('bets')
                        ->setExecutionAmountLimit(1),
                    $defaultConnectionName
                        ? MultiTenantConfiguration::createWithDefaultConnection($tenantHeaderName, $tenantConnectionMapping, $defaultConnectionName, 'multi_tenant_connection')
                        : MultiTenantConfiguration::create($tenantHeaderName, $tenantConnectionMapping, 'multi_tenant_connection')
                ]),
            allowGatewaysToBeRegisteredInContainer: true,
            enableAsynchronousProcessing: [
                FakeMessageChannelWithConnectionFactoryBuilder::create('bets', 'multi_tenant_connection')
            ],
        );
    }
}