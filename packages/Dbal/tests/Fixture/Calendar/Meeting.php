<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Calendar;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
final class Meeting
{
    public function __construct(
        #[Identifier] public string $meetingId,
    ) {
    }
}
