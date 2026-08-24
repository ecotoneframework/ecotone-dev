<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Calendar;

use Ecotone\Api\Aggregate;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class Meeting
{
    public function __construct(
        #[Identifier] public string $meetingId,
    ) {
    }
}
