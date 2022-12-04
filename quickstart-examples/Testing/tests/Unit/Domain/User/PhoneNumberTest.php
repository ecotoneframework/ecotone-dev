<?php

declare(strict_types=1);

namespace Test\App\Unit\Domain\User;

use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\PhoneNumber;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    public function test_creating_valid_phone_number()
    {
        $phoneNumber = "+85294504964";
        $email = PhoneNumber::create($phoneNumber);

        $this->assertEquals($phoneNumber, $email->toString());;
    }

    public function test_throwing_exception_during_creation_of_invalid_phone_number()
    {
        $phoneNumber = "518-518-518-518";

        $this->expectException(\InvalidArgumentException::class);

        PhoneNumber::create($phoneNumber);
    }
}