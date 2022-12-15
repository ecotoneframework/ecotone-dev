<?php

declare(strict_types=1);

namespace Test\App\Unit\Domain\User;

use App\Testing\Domain\User\Command\RegisterUser;
use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\Event\UserWasRegistered;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\User\User;
use App\Testing\Domain\Verification\TokenGenerator;
use App\Testing\Domain\Verification\VerificationProcess;
use App\Testing\Infrastructure\MessagingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UserTest extends TestCase
{
    public function test_registering_user()
    {
        $userId = Uuid::uuid4();
        $email = Email::create("test@wp.pl");
        $phoneNumber = PhoneNumber::create("148518518518");
        $command = new RegisterUser($userId, "johny", $email, $phoneNumber);

        $user = User::register($command);

        /** Comparing published events after registration */
        $this->assertEquals(
            [new UserWasRegistered($userId, $email, $phoneNumber)],
            $user->getRecordedEvents()
        );
    }

    public function test_registering_user_with_message_flows()
    {
        $userId = Uuid::uuid4();
        $email = Email::create("test@wp.pl");
        $phoneNumber = PhoneNumber::create("148518518518");

        /** Comparing published events after registration */
        $this->assertEquals(
            [new UserWasRegistered($userId, $email, $phoneNumber)],
            EcotoneLite::bootstrapFlowTesting([User::class])
                ->sendCommand(new RegisterUser($userId, "johny", $email, $phoneNumber))
                ->getRecordedEvents()
        );
    }

    public function test_registering_user_with_message_flows_by_verifying_state()
    {
        $userId = Uuid::uuid4();
        $email = Email::create("test@wp.pl");
        $phoneNumber = PhoneNumber::create("148518518518");

        $this->assertTrue(
            EcotoneLite::bootstrapFlowTesting([User::class])
                ->sendCommand(new RegisterUser($userId, "johny", $email, $phoneNumber))
                ->sendCommandWithRoutingKey("user.block", metadata: ["aggregate.id" => $userId])
                ->getAggregate(User::class, $userId)
                ->isBlocked()
        );
    }

    public function test_blocking_user()
    {
        $user = User::register(new RegisterUser(
                Uuid::uuid4(),
                "johny",
                Email::create("test@wp.pl"),
                PhoneNumber::create("148518518518"))
        );

        $user->block();

        $this->assertTrue($user->isBlocked());
    }
}