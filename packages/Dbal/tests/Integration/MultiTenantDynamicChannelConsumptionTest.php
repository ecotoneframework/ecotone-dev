<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiTenantDynamicChannelConsumptionTest extends DbalMessagingTestCase
{
    public function test_single_consumer_of_header_based_dynamic_channel_consumes_messages_of_all_tenants(): void
    {
        $handler = new DbalTenantOwnerCreationHandler();
        $suffix = Uuid::v7()->toBase58();
        $tenantAChannel = 'owner_creation_tenant_a_' . $suffix;
        $tenantBChannel = 'owner_creation_tenant_b_' . $suffix;

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [DbalTenantOwnerCreationHandler::class],
            [$handler, DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($tenantAChannel),
                    DbalBackedMessageChannelBuilder::create($tenantBChannel),
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

class DbalTenantOwnerCreationHandler
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
