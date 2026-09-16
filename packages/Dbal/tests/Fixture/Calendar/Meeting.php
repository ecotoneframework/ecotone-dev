<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Calendar;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Identifier;

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
