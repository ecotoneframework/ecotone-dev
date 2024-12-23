<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Subscription;

use Ecotone\EventSourcingV2\EventStore\LogEventId;

readonly class SubscriptionQuery
{
    public function __construct(
        public array       $streamIds = [],
        public ?LogEventId $from = null,
        public ?LogEventId $to = null,
        public bool        $allowGaps = false,
        public ?int        $limit = null,
    ) {
    }

    public function withFromPosition(?LogEventId $position)
    {
        return new self(
            $this->streamIds,
            $position,
            $this->to,
            $this->allowGaps,
            $this->limit,
        );
    }
}