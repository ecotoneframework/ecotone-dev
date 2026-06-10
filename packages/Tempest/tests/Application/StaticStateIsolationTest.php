<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Modelling\CommandBus;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\Fixture\User\User;
use Test\Ecotone\Tempest\Fixture\User\UserRepository;

/**
 * licence Apache-2.0
 * @internal
 */
final class StaticStateIsolationTest extends EcotoneIntegrationTestCase
{
    public function test_second_boot_in_same_process_sees_fresh_messaging_system_state(): void
    {
        $userId = 'user-first-boot';

        $commandBus = $this->container->get(CommandBus::class);
        $commandBus->sendWithRouting('user.register', $userId);

        $userRepository = $this->container->get(UserRepository::class);
        $this->assertEquals(User::register($userId), $userRepository->getUser($userId));

        restore_exception_handler();
        restore_error_handler();

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->setupKernel();

        $secondCommandBus = $this->container->get(CommandBus::class);
        $secondUserRepository = $this->container->get(UserRepository::class);

        $secondUserId = 'user-second-boot';
        $secondCommandBus->sendWithRouting('user.register', $secondUserId);

        $this->assertEquals(User::register($secondUserId), $secondUserRepository->getUser($secondUserId));
    }
}
