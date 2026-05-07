<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\MultiTenant\FakeConnectionFactory;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventPoller;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventReceiver;

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
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('WithTenantResolver');
        $this->expectExceptionMessage(ExternalEventPoller::class . '::poll');
        $this->expectExceptionMessage('Enterprise licence');

        $this->bootstrapWithoutLicence();
    }

    public function test_bootstraps_successfully_with_enterprise_licence(): void
    {
        $this->bootstrapWithLicence(LicenceTesting::VALID_LICENCE);

        $this->assertTrue(true, 'Bootstrap with valid Enterprise licence must succeed when WithTenantResolver is in use.');
    }

    private function bootstrapWithoutLicence(): void
    {
        EcotoneLite::bootstrapFlowTesting(
            [ExternalEventPoller::class, ExternalEventReceiver::class],
            [new ExternalEventPoller(), new ExternalEventReceiver(), 'tenant_a_connection' => new FakeConnectionFactory()],
            $this->serviceConfiguration(),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('external_processing'),
            ],
        );
    }

    private function bootstrapWithLicence(string $licenceKey): void
    {
        EcotoneLite::bootstrapFlowTesting(
            [ExternalEventPoller::class, ExternalEventReceiver::class],
            [new ExternalEventPoller(), new ExternalEventReceiver(), 'tenant_a_connection' => new FakeConnectionFactory()],
            $this->serviceConfiguration(),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('external_processing'),
            ],
            licenceKey: $licenceKey,
        );
    }

    private function serviceConfiguration(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()
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
            ]);
    }
}
