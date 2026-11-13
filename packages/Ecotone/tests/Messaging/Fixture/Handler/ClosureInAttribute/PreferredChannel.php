<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ClosureInAttribute;

/**
 * licence Apache-2.0
 */
enum PreferredChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
