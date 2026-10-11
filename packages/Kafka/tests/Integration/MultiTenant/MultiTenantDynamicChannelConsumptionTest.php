<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration\MultiTenant;

use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class MultiTenantDynamicChannelConsumptionTest extends TestCase
{
    public function test_single_consumer_of_header_based_dynamic_channel_consumes_messages_of_all_tenants(): void
    {
        $handler = new TenantOwnerCreationHandler();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [TenantOwnerCreationHandler::class],
            [$handler, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: 'channel_owner_creation_tenant_a',
                        topicName: 'owner_creation_tenant_a_' . Uuid::v7()->toRfc4122(),
                    ),
                    KafkaMessageChannelBuilder::create(
                        channelName: 'channel_owner_creation_tenant_b',
                        topicName: 'owner_creation_tenant_b_' . Uuid::v7()->toRfc4122(),
                    ),
                    DynamicMessageChannelBuilder::createWithHeaderBasedStrategy(
                        thisMessageChannelName: 'channel_owner_creation',
                        headerName: 'tenant',
                        headerMapping: [
                            'tenant_a' => 'channel_owner_creation_tenant_a',
                            'tenant_b' => 'channel_owner_creation_tenant_b',
                        ],
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->sendCommandWithRoutingKey('owner.create', 'owner_a', metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->sendCommandWithRoutingKey('owner.create', 'owner_b', metadata: ['tenant' => 'tenant_b']);

        $ecotoneLite->run('channel_owner_creation', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            maxExecutionTimeInMilliseconds: 60000,
        ));

        $this->assertEqualsCanonicalizing(
            ['tenant_a' => 'owner_a', 'tenant_b' => 'owner_b'],
            $handler->createdOwners,
        );
    }
}

class TenantOwnerCreationHandler
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
