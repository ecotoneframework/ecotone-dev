<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Messaging\Support\MessageBuilder;
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
final class WithTenantResolverLicensingTest extends TestCase
{
    public function test_throws_licensing_exception_when_no_enterprise_licence_provided(): void
    {
        $service = $this->newTenantResolvingPoller();

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('WithTenantResolver');
        $this->expectExceptionMessage($service::class . '::poll');
        $this->expectExceptionMessage('Enterprise licence');

        $this->bootstrap($service, null);
    }

    public function test_bootstraps_successfully_with_enterprise_licence(): void
    {
        $this->bootstrap($this->newTenantResolvingPoller(), LicenceTesting::VALID_LICENCE);

        $this->assertTrue(true, 'Bootstrap with valid Enterprise licence must succeed when WithTenantResolver is in use.');
    }

    private function newTenantResolvingPoller(): object
    {
        return new class () {
            #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function poll(): ?Message
            {
                return MessageBuilder::withPayload('payload')->setHeader('source', 'tenant_a')->build();
            }
        };
    }

    private function bootstrap(object $service, ?string $licenceKey): void
    {
        EcotoneLite::bootstrapFlowTesting(
            [$service::class],
            [$service, 'tenant_a_connection' => new FakeConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    MultiTenantConfiguration::createWithDefaultConnection(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection'],
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
            licenceKey: $licenceKey,
        );
    }
}
