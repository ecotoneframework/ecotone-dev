<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Domain\Event;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::EVENT_NAME)]
final readonly class UserWasRegistered
{
    public const EVENT_NAME = 'user.was_registered';

    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
    ) {
    }
}
