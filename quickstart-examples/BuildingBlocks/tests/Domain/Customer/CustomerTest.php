<?php

declare(strict_types=1);

namespace Domain\Customer;

use App\Domain\Customer\Command\ChangeEmail;
use App\Domain\Customer\Command\RegisterCustomer;
use App\Domain\Customer\Customer;
use App\Domain\Customer\Email;
use App\Domain\Customer\FullName;
use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CustomerTest extends TestCase
{
    public function test_changing_email_for_new_registered_customer()
    {
        $customerId = Uuid::uuid4();

        $this->assertEquals(
            new Email('johny.bravo@gmail.com'),
            EcotoneLite::bootstrapFlowTesting([Customer::class])
                ->sendCommand(new RegisterCustomer(
                    $customerId,
                    new FullName('John Doe'),
                    new Email('john.doe@gmail.com')
                ))
                ->sendCommand(new ChangeEmail(
                    $customerId,
                    new Email('johny.bravo@gmail.com')
                ))
                ->getAggregate(Customer::class, $customerId)
                ->getEmail()
        );
    }
}