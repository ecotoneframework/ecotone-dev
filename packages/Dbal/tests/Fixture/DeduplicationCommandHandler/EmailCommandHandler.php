<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler;

use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class EmailCommandHandler
{
    private int $called = 0;

    #[Deduplicated]
    #[CommandHandler('email_event_handler.handle')]
    public function handle(): void
    {
        $this->called++;
    }

    #[Deduplicated('emailId')]
    #[CommandHandler('email_event_handler.handle_with_custom_deduplication_header')]
    public function handleWithCustomDeduduplication(): void
    {
        $this->called++;
    }

    #[QueryHandler('email_event_handler.getCallCount')]
    public function getCallCount(): int
    {
        return $this->called;
    }
}
