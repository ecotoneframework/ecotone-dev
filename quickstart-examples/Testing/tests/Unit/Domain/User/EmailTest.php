<?php

declare(strict_types=1);

namespace Test\App\Testing\Domain\User;

use App\Testing\Domain\User\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function test_creating_valid_email()
    {
        $emailAddress = "test@wp.pl";
        $email = Email::create($emailAddress);

        $this->assertEquals($emailAddress, $email->toString());
    }

    public function test_throwing_exception_during_creation_of_invalid_email()
    {
        $emailAddress = "test";

        $this->expectException(\InvalidArgumentException::class);

        Email::create($emailAddress);
    }
}