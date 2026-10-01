<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiTenantDynamicStreamChannelConsumptionTest extends AmqpMessagingTestCase
{
    public function setUp(): void
    {
        if (getenv('AMQP_IMPLEMENTATION') !== 'lib') {
            $this->markTestSkipped('Stream tests require AMQP lib');
        }
    }

    public function test_single_consumer_of_header_based_dynamic_channel_consumes_messages_of_all_tenants(): void
    {
        $handler = new AmqpStreamTenantOwnerCreationHandler();
        $suffix = Uuid::v7()->toBase58();
        $tenantAChannel = 'owner_creation_tenant_a_' . $suffix;
        $tenantBChannel = 'owner_creation_tenant_b_' . $suffix;

        $ecotoneLite = $this->bootstrapForTesting(
            [AmqpStreamTenantOwnerCreationHandler::class],
            [$handler, ...$this->getConnectionFactoryReferences()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($tenantAChannel),
                    AmqpStreamChannelBuilder::create($tenantAChannel, 'first', AmqpLibConnection::class, $tenantAChannel),
                    AmqpQueue::createStreamQueue($tenantBChannel),
                    AmqpStreamChannelBuilder::create($tenantBChannel, 'first', AmqpLibConnection::class, $tenantBChannel),
                    DynamicMessageChannelBuilder::createWithHeaderBasedStrategy(
                        thisMessageChannelName: 'channel_owner_creation',
                        headerName: 'tenant',
                        headerMapping: [
                            'tenant_a' => $tenantAChannel,
                            'tenant_b' => $tenantBChannel,
                        ],
                    ),
                ]),
        );

        $ecotoneLite->getCommandBus()->sendWithRouting('owner.create', 'owner_a', metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->getCommandBus()->sendWithRouting('owner.create', 'owner_b', metadata: ['tenant' => 'tenant_b']);

        $ecotoneLite->run('channel_owner_creation', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            maxExecutionTimeInMilliseconds: 30000,
        ));

        $this->assertEqualsCanonicalizing(
            ['tenant_a' => 'owner_a', 'tenant_b' => 'owner_b'],
            $handler->createdOwners,
        );
    }
}

class AmqpStreamTenantOwnerCreationHandler
{
    /** @var array<string, string> */
    public array $createdOwners = [];

    #[Asynchronous('channel_owner_creation')]
    #[CommandHandler('owner.create', 'owner_creation_endpoint')]
    public function handle(string $owner, #[Header('tenant')] string $tenant): void
    {
        $this->createdOwners[$tenant] = $owner;
    }
}
