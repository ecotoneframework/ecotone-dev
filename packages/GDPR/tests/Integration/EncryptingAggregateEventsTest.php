<?php

declare(strict_types=1);

namespace Test\Ecotone\GDPR\Integration;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Ecotone\GDPR\Key\StaticEncryptionKeyProvider;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\GDPR\Fixture\User\Register;
use Test\Ecotone\GDPR\Fixture\User\User;
use Test\Ecotone\GDPR\Fixture\User\UserRegistered;
use Test\Ecotone\GDPR\GDPRTestCase;

class EncryptingAggregateEventsTest extends GDPRTestCase
{
    public function test_recorder_events_will_be_persisted_encrypted(): void
    {
        $key = Key::createNewRandomKey();
        $ecotone = $this->bootstrap($key);

        $ecotone->withEventsFor(
            'user-1',
            User::class,
            [
                new UserRegistered('user-1', 'john@doe.com'),
            ]
        );

        self::assertEquals(
            Crypto::encrypt('john@doe.com', $key),
            $this->getConnection()->fetchOne("select payload->>'email' from _12dea96fec20593566ab75692c9949596833adc9 where event_name = ? and metadata->>'_aggregate_id' = ?", ['user.registered', 'user-1'])
        );

        $ecotone->sendCommand(new Register('user-2', 'marry-ann@doe.com'));

        self::assertEquals(
            Crypto::encrypt('marry-ann@doe.com', $key),
            $this->getConnection()->fetchOne("select payload->>'email' from _12dea96fec20593566ab75692c9949596833adc9 where event_name = ? and metadata->>'_aggregate_id' = ?", ['user.registered', 'user-2'])
        );
    }

    private function bootstrap(Key $key): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [User::class],
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(
                    ModulePackageList::allPackagesExcept(
                        [
                            ModulePackageList::DBAL_PACKAGE,
                            ModulePackageList::EVENT_SOURCING_PACKAGE,
                            ModulePackageList::GDPR_PACKAGE,
                            ModulePackageList::JMS_CONVERTER_PACKAGE,
                        ]
                    )
                )
                ->withExtensionObjects([
                    StaticEncryptionKeyProvider::create($key),
                ])
                ->withNamespaces(['Test\Ecotone\GDPR\Fixture\User']),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }
}
