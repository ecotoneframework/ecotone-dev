<?php

declare(strict_types=1);

namespace Test\App\Testing\Domain\User;

use App\Testing\Domain\User\Command\RegisterUser;
use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\Event\UserWasRegistered;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\User\User;
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
}