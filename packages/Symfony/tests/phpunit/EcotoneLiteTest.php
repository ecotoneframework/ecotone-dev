<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Fixture\User\User;
use Fixture\User\UserRepository;
use Fixture\User\UserService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
final class EcotoneLiteTest extends KernelTestCase
{
    public function test_when_messaging_configured_in_container_replacing_it_with_test_one()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [User::class, UserRepository::class, UserService::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithDefaults()
                ->doNotLoadCatalog()
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllStateStoredAggregates(),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
        );

        $userId = '123';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('user.register', $userId);

        /** @var UserRepository $userRepository */
        $userRepository = $ecotoneTestSupport->getGatewayByName(UserRepository::class);

        $this->assertEquals(User::register($userId), $userRepository->getUser($userId));
    }
}
