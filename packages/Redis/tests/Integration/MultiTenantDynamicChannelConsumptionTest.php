<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Enqueue\Redis\RedisConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Redis\ConnectionTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiTenantDynamicChannelConsumptionTest extends ConnectionTestCase
{
    public function test_single_consumer_of_header_based_dynamic_channel_consumes_messages_of_all_tenants(): void
    {
        $handler = new RedisTenantOwnerCreationHandler();
        $suffix = Uuid::v7()->toBase58();
        $tenantAChannel = 'owner_creation_tenant_a_' . $suffix;
        $tenantBChannel = 'owner_creation_tenant_b_' . $suffix;

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RedisTenantOwnerCreationHandler::class],
            [$handler, RedisConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    RedisBackedMessageChannelBuilder::create($tenantAChannel),
                    RedisBackedMessageChannelBuilder::create($tenantBChannel),
                    DynamicMessageChannelBuilder::createWithHeaderBasedStrategy(
                        thisMessageChannelName: 'channel_owner_creation',
                        headerName: 'tenant',
                        headerMapping: [
                            'tenant_a' => $tenantAChannel,
                            'tenant_b' => $tenantBChannel,
                        ],
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->sendCommandWithRoutingKey('owner.create', 'owner_a', metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->sendCommandWithRoutingKey('owner.create', 'owner_b', metadata: ['tenant' => 'tenant_b']);

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

class RedisTenantOwnerCreationHandler
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
