<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Domain\Event;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::EVENT_NAME)]
final readonly class UserWasDeactivated
{
    public const EVENT_NAME = 'user.was_deactivated';

    public function __construct(
        public string $userId,
    ) {
    }
}
