<?php

declare(strict_types=1);

namespace Test\Ecotone\GDPR\Fixture\User;

final class Register
{
    public function __construct(public string $id, public string $email) {}
}
