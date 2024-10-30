<?php

declare(strict_types=1);

namespace Test\Ecotone\GDPR\Fixture\User;

use Ecotone\GDPR\Attribute\PersonalData;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('user.registered')]
final readonly class UserRegistered
{
    public function __construct(
        #[Identifier]
        public string $id,
        #[PersonalData]
        public string $email
    ) {
    }
}
