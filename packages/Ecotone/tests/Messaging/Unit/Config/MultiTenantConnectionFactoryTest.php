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
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConnectionFactory;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Enqueue\Null\NullContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Messaging\Fixture\Channel\FakeMessageChannelWithConnectionFactoryBuilder;
use Test\Ecotone\Messaging\Fixture\Config\FakeConnectionFactory;
use Test\Ecotone\Messaging\Fixture\Handler\SuccessServiceActivator;
use Test\Ecotone\Modelling\Fixture\Collector\BetService;

final class MultiTenantConnectionFactoryTest extends TestCase
{
    public function test_using_default_connection_factory_for_sending_when_tenant_not_mapped()
    {
        $notExpectedContext = new NullContext();
        $expectedContext = new NullContext();
        $connections = [
            'tenant_a_connection' => new FakeConnectionFactory($notExpectedContext),
            'default_tenant_connection' => new FakeConnectionFactory($expectedContext)
        ];

        $ecotoneLite = $this->boostrapEcotone('tenant', $connections, defaultConnectionName: 'default_tenant_connection');
        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false, metadata: ['tenant' => 'unknown']);

        $usedConnectionContext = $ecotoneLite->getMessageChannel('bets')->receive()->getHeaders()->get('connectionContext');
        $this->assertNotSame($notExpectedContext, $usedConnectionContext);
        $this->assertSame($expectedContext, $usedConnectionContext);
    }

    public function test_using_default_mapped_connection_factory_for_sending()
    {
        $notExpectedContext = new NullContext();
        $expectedContext = new NullContext();
        $connections = [
            'tenant_a_connection' => new FakeConnectionFactory($notExpectedContext),
            'tenant_b_connection' => new FakeConnectionFactory($expectedContext)
        ];

        $ecotoneLite = $this->boostrapEcotone('tenant', $connections, [
            'tenant_a' => 'tenant_a_connection',
            'tenant_b' => 'tenant_b_connection'
        ]);
        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false, metadata: ['tenant' => 'tenant_b']);

        $usedConnectionContext = $ecotoneLite->getMessageChannel('bets')->receive()->getHeaders()->get('connectionContext');
        $this->assertNotSame($notExpectedContext, $usedConnectionContext);
        $this->assertSame($expectedContext, $usedConnectionContext);
    }

    public function test_throwing_exception_when_no_tenant_header_found()
    {
        $notExpectedContext = new NullContext();
        $expectedContext = new NullContext();
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
        $notExpectedContext = new NullContext();
        $expectedContext = new NullContext();
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

    public function test_throwing_exception_when_trying_to_fetch_from_multi_tenant_connection()
    {
        $notExpectedContext = new NullContext();
        $expectedContext = new NullContext();
        $connections = [
            'tenant_a_connection' => new FakeConnectionFactory($notExpectedContext),
            'tenant_b_connection' => new FakeConnectionFactory($expectedContext)
        ];

        $ecotoneLite = $this->boostrapEcotone('tenant', $connections, [
            'tenant_a' => 'tenant_a_connection',
            'tenant_b' => 'tenant_b_connection'
        ], verifyConnectionOnPoll: true);


        $ecotoneLite->sendCommandWithRoutingKey('asyncMakeBet', false, metadata: ['tenant' => 'tenant_a']);

        $this->expectException(InvalidArgumentException::class);

        /** We are not aware at this moment, from which connection should we fetch. Therefore exception is thrown */
        $ecotoneLite->run('bets');
    }

    /**
     * @param array<string, FakeConnectionFactory> $connections
     */
    private function boostrapEcotone(
        string $tenantHeaderName,
        array $connections,
        array $tenantConnectionMapping = [],
        ?string $defaultConnectionName = null,
        bool $verifyConnectionOnPoll = false,
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
                        ? MultiTenantConfiguration::createWithDefaultConnection('multi_tenant_connection', $tenantHeaderName, $tenantConnectionMapping, $defaultConnectionName)
                        : MultiTenantConfiguration::create('multi_tenant_connection', $tenantHeaderName, $tenantConnectionMapping)
                ]),
            allowGatewaysToBeRegisteredInContainer: true,
            enableAsynchronousProcessing: [
                FakeMessageChannelWithConnectionFactoryBuilder::create('bets', 'multi_tenant_connection', $verifyConnectionOnPoll)
            ],
        );
    }
}