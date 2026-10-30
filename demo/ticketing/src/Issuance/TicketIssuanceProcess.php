<?php

declare(strict_types=1);

namespace App\Issuance;

use Ecotone\Api\Attribute\BusinessMethod;
use Ecotone\Api\Attribute\Header;

interface TicketIssuanceProcess
{
    #[BusinessMethod(TicketIssuance::INPUT_CHANNEL)]
    public function issue(IssueTickets $command, #[Header('concertId')] string $concertId): void;
}
