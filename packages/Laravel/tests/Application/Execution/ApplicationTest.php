<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Application\Execution;

use Ecotone\Laravel\Queue\LaravelQueueMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\MessagingTestSupport;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler\AsyncCommandHandler;
use Test\Ecotone\Laravel\Fixture\Order\PlaceOrder;
use Test\Ecotone\Laravel\Fixture\User\User;
use Test\Ecotone\Laravel\Fixture\User\UserRepository;

/**
 * @internal
 */
final class ApplicationTest extends TestCase
{
    public function test_boot_application_with_ecotone()
    {
        $app = $this->createApplication();

        $this->assertInstanceOf(
            CommandBus::class,
            $app->get(CommandBus::class)
        );
    }

    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_working_with_gateways_automatically_registered_in_dependency_container()
    {
        $app = $this->createApplication();

        $userId = '123';
        /** @var CommandBus $commandBus */
        $commandBus = $app->get(CommandBus::class);
        $commandBus->sendWithRouting('user.register', $userId);

        /** @var UserRepository $userRepository */
        $userRepository = $app->get(UserRepository::class);
        $this->assertEquals(
            User::register($userId),
            $userRepository->getUser($userId)
        );
    }

    public function test_sending_command_using_expression_language()
    {
        $app = $this->createApplication();
        /** @var CommandBus $commandBus */
        $commandBus = $app->get(CommandBus::class);
        /** @var QueryBus $queryBus */
        $queryBus = $app->get(QueryBus::class);

        $amount = 123;
        $commandBus->sendWithRouting('setAmount', ['amount' => $amount]);

        $this->assertEquals(
            $amount,
            $queryBus->sendWithRouting('getAmount')
        );
    }

    public function test_it_boots_messaging_system_with_test_support(): void
    {
        $app = $this->createApplication();

        $messagingSystem = $app->get(ConfiguredMessagingSystem::class);

        self::assertInstanceOf(
            MessagingTestSupport::class,
            $messagingSystem->getGatewayByName(MessagingTestSupport::class)
        );
    }
}
