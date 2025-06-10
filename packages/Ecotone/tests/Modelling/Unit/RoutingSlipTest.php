<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\CommandEventFlow\AuditLog;
use Test\Ecotone\Modelling\Fixture\CommandEventFlow\MerchantCreated;
use Test\Ecotone\Modelling\Fixture\CommandEventFlow\MerchantSubscriber;
use Test\Ecotone\Modelling\Fixture\CommandEventFlow\MerchantSubscriberWithMetadata;
use Test\Ecotone\Modelling\Fixture\CommandEventFlow\RegisterUser;
use Test\Ecotone\Modelling\Fixture\CommandEventFlow\User;

final class RoutingSlipTest extends TestCase
{
    public function test_using_routing_slip_on_factory_command_handler(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [User::class, AuditLog::class],
            [new AuditLog()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
        );

        $ecotoneLite->sendDirectToChannel(
            RegisterUser::class,
            new RegisterUser('123'),
            metadata: [
                MessageHeaders::ROUTING_SLIP => 'audit',
            ]
        );
        $this->assertEquals(['123'], $ecotoneLite->sendQueryWithRouting('audit.getData'));
        $this->assertNotNull($ecotoneLite->getAggregate(User::class, '123'));
    }

    public function test_routing_slip_will_not_be_propagated_by_command_bus(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [User::class, AuditLog::class],
            [new AuditLog()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
        );

        $ecotoneLite->sendCommand(
            new RegisterUser('123'),
            metadata: [
                MessageHeaders::ROUTING_SLIP => 'audit',
            ]
        );
        $this->assertEquals([], $ecotoneLite->sendQueryWithRouting('audit.getData'));
        $this->assertNotNull($ecotoneLite->getAggregate(User::class, '123'));
    }

    public function test_routing_slip_is_not_propagated_to_next_gateway_invocation(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [MerchantSubscriber::class, AuditLog::class, User::class],
            [new MerchantSubscriber(), new AuditLog()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
        );

        $ecotoneLite->sendDirectToChannel(
            'merchantToUser',
            $event = new MerchantCreated('123'),
            metadata: [
                MessageHeaders::ROUTING_SLIP => 'audit',
            ]
        );
        $this->assertEquals([$event], $ecotoneLite->sendQueryWithRouting('audit.getData'));
    }

    public function test_routing_slip_will_not_be_propagated_by_event_bus(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [MerchantSubscriber::class, AuditLog::class, User::class],
            [new MerchantSubscriber(), new AuditLog()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
        );

        $ecotoneLite->publishEvent(
            new MerchantCreated('123'),
            metadata: [
                MessageHeaders::ROUTING_SLIP => 'audit',
            ]
        );
        $this->assertEquals([], $ecotoneLite->sendQueryWithRouting('audit.getData'));
    }

    public function test_routing_slip_is_not_propagated_when_whole_metadata_is_passed(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [MerchantSubscriberWithMetadata::class, AuditLog::class, User::class],
            [new MerchantSubscriberWithMetadata(), new AuditLog()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
        );

        $ecotoneLite->sendDirectToChannel(
            'merchantToUser',
            $event = new MerchantCreated('123'),
            metadata: [
                MessageHeaders::ROUTING_SLIP => 'audit',
            ]
        );
        $this->assertEquals([$event], $ecotoneLite->sendQueryWithRouting('audit.getData'));
    }
}