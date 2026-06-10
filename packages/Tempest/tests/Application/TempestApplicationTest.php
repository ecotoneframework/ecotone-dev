<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\Fixture\User\User;
use Test\Ecotone\Tempest\Fixture\User\UserRepository;

/**
 * licence Apache-2.0
 * @internal
 */
final class TempestApplicationTest extends EcotoneIntegrationTestCase
{
    public function test_command_bus_resolves_from_tempest_container(): void
    {
        $this->assertInstanceOf(
            CommandBus::class,
            $this->container->get(CommandBus::class),
        );
    }

    public function test_gateways_injectable_and_command_handler_flow_runs_end_to_end(): void
    {
        $userId = '123';

        $commandBus = $this->container->get(CommandBus::class);
        $commandBus->sendWithRouting('user.register', $userId);

        $userRepository = $this->container->get(UserRepository::class);

        $this->assertEquals(
            User::register($userId),
            $userRepository->getUser($userId),
        );
    }

    public function test_expression_language_in_payload(): void
    {
        $commandBus = $this->container->get(CommandBus::class);
        $queryBus = $this->container->get(QueryBus::class);

        $amount = 123;
        $commandBus->sendWithRouting('setAmount', ['amount' => $amount]);

        $this->assertEquals(
            $amount,
            $queryBus->sendWithRouting('getAmount'),
        );
    }
}
